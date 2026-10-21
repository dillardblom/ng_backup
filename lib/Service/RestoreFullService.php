<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\DbDumper;
use OCA\NgBackup\Db\Target;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\LockedException;

/**
 * Disaster recovery on a fresh Nextcloud installation (same major version): replace the
 * database, restore the data directory and merge the backed-up config.php, see PLAN.md 3.7.
 *
 * Deliberately NOT handled yet (phase 3): restoring in place over a live, already-used
 * installation. The admin is expected to run this once, right after occ maintenance:install and
 * occ backup:key:import/backup:target:add, before anyone else touches the instance.
 */
final class RestoreFullService {
	/**
	 * Config keys that must keep pointing at THIS installation's actual, already-working
	 * database and data directory (set by occ maintenance:install), never at the old server's.
	 */
	/** App setting: when a full restore started that has not completed (see restoreFull()). */
	private const INTERRUPTED_KEY = 'restore_full_started';

	private const CONNECTION_KEYS = [
		'dbtype', 'dbhost', 'dbport', 'dbname', 'dbuser', 'dbpassword', 'dbpersistent',
		'dbtableprefix', 'dbdriveroptions', 'datadirectory',
		// set by restoreFull() itself for the length of the restore
		'maintenance',
	];

	public function __construct(
		private TargetService $targets,
		private RestoreService $restore,
		private LeaseService $leases,
		private IConfig $config,
		private IDBConnection $db,
		private IAppManager $appManager,
		private KeyService $keys,
	) {
	}

	/** Apps that only exist in a particular setup and are never needed elsewhere. */
	private const ENVIRONMENT_APPS = ['nextcloud-aio'];

	/**
	 * Whether this installation can receive $snapshotId, all problems at once:
	 * - the same Nextcloud major version is required;
	 * - apps that were enabled at backup time but are not here (they work again once installed);
	 * - tables in the backup that do not exist here: a backup made from 1.0.0-rc.2 on records
	 *   their structure and the restore creates them, an older one cannot (missingTables).
	 *
	 * @return array{ok:bool, currentMajor:string, snapshotMajor:string, missingApps:list<string>, missingTables:list<string>}
	 */
	public function checkCompatibility(Target $target, string $snapshotId, bool $skipMissingTables = false): array {
		$repo = $this->targets->repository($target);
		$meta = $repo->snapshotMeta($snapshotId);
		$currentMajor = explode('.', $this->config->getSystemValueString('version'))[0];
		$snapshotMajor = explode('.', (string)($meta['nextcloud'] ?? ''))[0];
		$enabled = array_flip($this->appManager->getEnabledApps());
		$missingApps = array_values(array_diff(array_keys($meta['apps'] ?? []), array_keys($enabled), self::ENVIRONMENT_APPS));
		$missingTables = [];
		$prefixMismatch = null;
		if (isset($meta['db'])) {
			$manifest = json_decode($repo->getObject($meta['db']), true, 512, JSON_THROW_ON_ERROR);
			$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
			$sourcePrefix = DbDumper::dumpPrefix($manifest);
			if ($sourcePrefix !== null && $sourcePrefix !== $prefix) {
				$prefixMismatch = [$sourcePrefix, $prefix];
			}
			$dumper = new DbDumper($this->db->getInner(), $prefix);
			$missingTables = array_values(array_filter($dumper->missingTables($manifest),
				fn (string $t) => !isset($manifest['tables'][$t]['schema']) && !str_starts_with($t, $prefix . 'ngb_')));
		}
		return [
			'ok' => $currentMajor === $snapshotMajor && $prefixMismatch === null && ($missingTables === [] || $skipMissingTables),
			'currentMajor' => $currentMajor,
			'snapshotMajor' => $snapshotMajor,
			'missingApps' => $missingApps,
			'missingTables' => $prefixMismatch === null ? $missingTables : [],
			'prefixMismatch' => $prefixMismatch,
		];
	}

	/** @return array{dbReport:array, dataFiles:int, configKeys:int, dbConsistent:bool, missingApps:list<string>, maintenanceLeftOn:bool} */
	public function restoreFull(Target $target, string $snapshotId, bool $skipMissingTables = false): array {
		// Maintenance mode for the whole restore: background jobs (cron.php stops in maintenance
		// mode), page visits (AJAX cron) and users must not write into tables or the data
		// directory while they are being replaced; a row written in between would collide with a
		// restored one or be silently lost. Switched off after a restore that completed. After an
		// error it stays on: the instance may be partly restored, and must not be used like that.
		// occ only offers app commands outside maintenance mode, so the admin switches it off
		// right before running the restore again (see RestoreFull). Already on before: left on.
		// On before the checks below, so that a run again after an error leaves the partly
		// restored instance open for seconds at most; off again when they refuse (nothing changed).
		// A marker in ng_backup's own app settings (which the restore keeps) says a restore started
		// and did not complete: then the instance may be partly restored, and a check that refuses
		// a run again must not open it.
		$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
		$interrupted = $appConfig->getValueInt(\OCA\NgBackup\AppInfo\Application::APP_ID, self::INTERRUPTED_KEY, 0) > 0;
		$wasInMaintenance = $this->config->getSystemValueBool('maintenance', false);
		$this->config->setSystemValue('maintenance', true);
		$reopen = function () use ($wasInMaintenance, $interrupted): void {
			if (!$wasInMaintenance && !$interrupted) {
				$this->config->setSystemValue('maintenance', false);
			}
		};
		try {
			$check = $this->checkCompatibility($target, $snapshotId, $skipMissingTables);
		} catch (\Throwable $e) {
			$reopen();
			throw $e;
		}
		if (!$check['ok']) {
			$reopen();
			throw new \RuntimeException($this->describeBlockers($check));
		}
		$appConfig->setValueInt(\OCA\NgBackup\AppInfo\Application::APP_ID, self::INTERRUPTED_KEY, time());
		$result = $this->restoreInMaintenance($target, $snapshotId, $skipMissingTables, $check);
		$appConfig->deleteKey(\OCA\NgBackup\AppInfo\Application::APP_ID, self::INTERRUPTED_KEY);
		if (!$wasInMaintenance) {
			$this->config->setSystemValue('maintenance', false);
		}
		return $result + ['maintenanceLeftOn' => $wasInMaintenance];
	}

	/** @return array{dbReport:array, dataFiles:int, configKeys:int, dbConsistent:bool, missingApps:list<string>} */
	private function restoreInMaintenance(Target $target, string $snapshotId, bool $skipMissingTables, array $check): array {
		return $this->withSharedLock($target, function (callable $refresh) use ($target, $snapshotId, $skipMissingTables, $check) {
			$beat = LeaseService::throttled($refresh);
			$repo = $this->targets->repository($target);
			$meta = $repo->snapshotMeta($snapshotId);

			$dbReport = null;
			$manifest = [];
			if (isset($meta['db'])) {
				$manifest = json_decode($repo->getObject($meta['db']), true, 512, JSON_THROW_ON_ERROR);
				$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
				// ng_backup's own tables (and its own rows in the shared appconfig table) must
				// survive this restore untouched: they describe the backup/key/target that is
				// actively performing it right now, on THIS installation, not the one that was
				// backed up. Overwriting them mid-restore would pull the rug from under it.
				foreach (array_keys($manifest['tables']) as $table) {
					/** @var string $table */
					if (str_starts_with($table, $prefix . 'ngb_')) {
						unset($manifest['tables'][$table]);
					}
				}
				// Sequences are keyed separately from tables; excluding a table does not exclude
				// its sequence, which would otherwise rewind a retained ngb_* table's sequence
				// below its current ids (PostgreSQL only -- see DbDumper::restoreSequences()).
				foreach (array_keys($manifest['sequences'] ?? []) as $seq) {
					/** @var string $seq */
					if (str_starts_with((string)($manifest['sequences'][$seq]['table'] ?? ''), $prefix . 'ngb_')) {
						unset($manifest['sequences'][$seq]);
					}
				}
				$ownAppConfig = $this->preserveOwnAppConfig();
				$dumper = new DbDumper($this->db->getInner(), $prefix);
				try {
					$dumper->restore($repo, $manifest, $dbReport, $beat, $skipMissingTables);
				} finally {
					$this->restoreOwnAppConfig($ownAppConfig);
				}
			}

			// Read (but do not yet apply) the backed-up config.php before touching the data
			// directory: once applied, 'secret' may differ from what ng_backup's own Target
			// options were encrypted with (see restoreFull()'s docblock), and every further
			// repository access through $this->targets would fail to decrypt them.
			/** @var array<string, mixed> $backupConfig */
			$backupConfig = $this->fetchBackupConfig($target, $snapshotId);

			$dataDir = rtrim($this->config->getSystemValueString('datadirectory'), '/');
			$dataFiles = $this->restore->restoreToDirectory($target, $snapshotId, 'data', $dataDir, stripPrefix: true, heartbeat: $beat);

			// Last step: nothing after this may need $this->targets->repository($target) again.
			if ($backupConfig !== []) {
				$this->targets->reencryptOptionsAround(
					fn (): mixed => $this->keys->reencryptForSecretRotation(fn (): mixed => $this->config->setSystemValues($backupConfig)),
				);
			}

			return ['dbReport' => $dbReport ?? ['skipped' => [], 'restored' => [], 'created' => [], 'missing' => []], 'dataFiles' => $dataFiles, 'configKeys' => count($backupConfig),
				'dbConsistent' => (bool)($manifest['consistent'] ?? true), 'missingApps' => $check['missingApps']];
		});
	}

	/** @return array<string, mixed> config.php's $CONFIG from the backup, minus the connection keys */
	private function fetchBackupConfig(Target $target, string $snapshotId): array {
		$tmp = sys_get_temp_dir() . '/ngb-restore-config-' . bin2hex(random_bytes(6));
		try {
			$this->restore->restoreToDirectory($target, $snapshotId, 'config', $tmp, stripPrefix: true);
			$path = $tmp . '/config.php';
			if (!is_file($path)) {
				return []; // nothing to merge; the live config.php stays as occ maintenance:install made it
			}
			$backupConfig = (static function () use ($path): array {
				$CONFIG = [];
				include $path;
				return $CONFIG;
			})();
			foreach (self::CONNECTION_KEYS as $key) {
				unset($backupConfig[$key]);
			}
			return $backupConfig;
		} finally {
			$this->rmrf($tmp);
		}
	}

	/** @return list<array<string, mixed>> raw appconfig rows (appid, configkey, configvalue, type, lazy) */
	private function preserveOwnAppConfig(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(\OCA\NgBackup\AppInfo\Application::APP_ID)));
		/** @var list<array<string, mixed>> */
		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * @param list<array<string, mixed>> $rows raw appconfig rows, as returned by preserveOwnAppConfig()
	 *
	 * Runs in its own transaction (DbDumper::restore() already committed its own by the time
	 * this is called): not a full guarantee against a crash spanning both, but means this step
	 * itself cannot leave ng_backup's appconfig rows half deleted, half reinserted.
	 */
	private function restoreOwnAppConfig(array $rows): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('appconfig')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter(\OCA\NgBackup\AppInfo\Application::APP_ID)));
			$qb->executeStatement();
			foreach ($rows as $row) {
				$ins = $this->db->getQueryBuilder();
				$ins->insert('appconfig')->values([
					'appid' => $ins->createNamedParameter($row['appid']),
					'configkey' => $ins->createNamedParameter($row['configkey']),
					'configvalue' => $ins->createNamedParameter($row['configvalue']),
					'type' => $ins->createNamedParameter($row['type'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
					'lazy' => $ins->createNamedParameter($row['lazy'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				]);
				$ins->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function rmrf(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
			$f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
		}
		rmdir($dir);
	}

	private function describeBlockers(array $check): string {
		$parts = [];
		if ($check['currentMajor'] !== $check['snapshotMajor']) {
			$parts[] = "this installation is Nextcloud {$check['currentMajor']}, the backup is from {$check['snapshotMajor']} (same major version required)";
		}
		if (($check['prefixMismatch'] ?? null) !== null) {
			[$source, $here] = $check['prefixMismatch'];
			$parts[] = "the backup's tables use the prefix '$source', this installation '$here': install Nextcloud again with 'dbtableprefix' => '$source' in config/config.php before occ maintenance:install, and restore again";
		}
		if ($check['missingTables'] !== []) {
			$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
			$parts[] = DbDumper::describeMissing($check['missingTables'], $prefix);
		}
		return implode("\n", $parts);
	}

	private function withSharedLock(Target $target, callable $fn): mixed {
		try {
			// Refreshed during the database and data restore (per row batch, per blob), so a
			// cleanup cannot take over the location while a long restore is still reading it.
			return $this->leases->with(PruneService::lockKey($target), LeaseService::SHARED, 7200, $fn);
		} catch (LockedException) {
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is in progress; try again later');
		}
	}
}
