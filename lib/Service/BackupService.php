<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\DbDumper;
use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Db\Snapshot;
use OCA\NgBackup\Db\SnapshotMapper;
use OCA\NgBackup\Db\Target;
use OCA\NgBackup\Job\BackupRun;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Full backups of this installation: database (one consistent transaction), config and the
 * data directory, as a resumable run whose state is stored in ngb_runs after every step.
 *
 * Excluded by default: previews, log files, the updater's backups and each user's cache and
 * temporary uploads (all regenerable or temporary).
 */
final class BackupService {
	public const KIND_FULL = 'full';

	public function __construct(
		private TargetService $targets,
		private KeyService $keys,
		private RunMapper $runs,
		private SnapshotMapper $snapshots,
		private IConfig $config,
		private IDBConnection $db,
		private IAppManager $appManager,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
		private AlertService $alerts,
		private LeaseService $leases,
	) {
	}

	public function start(Target $target, string $label = ''): Run {
		if (!$this->keys->everConfirmed()) {
			throw new \RuntimeException('Confirm the recovery kit first (occ backup:key:confirm)');
		}
		if ($this->config->getSystemValue('objectstore', null) !== null) {
			throw new \RuntimeException('Object storage as primary storage is not supported yet');
		}
		// Check and create the run under the location's exclusive lock, so a cleanup cannot start
		// in between (it takes the same lock and refuses while a run exists).
		// A run that is already in progress is simply continued; no lease needed for that.
		$running = $this->runs->findRunning($target->getId(), self::KIND_FULL);
		if ($running !== []) {
			return $running[0];
		}
		try {
			return $this->leases->with(PruneService::lockKey($target), LeaseService::EXCLUSIVE, 60, fn () => $this->createRun($target, $label));
		} catch (LockedException) {
			throw new \RuntimeException('A cleanup or another start for ' . $target->getName() . ' is running; try again later');
		}
	}

	private function createRun(Target $target, string $label): Run {
		$running = $this->runs->findRunning($target->getId(), self::KIND_FULL);
		if ($running !== []) {
			return $running[0];
		}
		$dataDir = rtrim($this->config->getSystemValueString('datadirectory', \OC::$SERVERROOT . '/data'), '/');
		$parent = $this->snapshots->latest($target->getId(), self::KIND_FULL);

		$files = BackupRun::start(
			['config' => rtrim(\OC::$configDir, '/'), 'data' => $dataDir],
			$parent?->getSnapshotId(),
			$label,
			$this->excludes($dataDir),
			['kind' => self::KIND_FULL, 'nextcloud' => $this->config->getSystemValueString('version'),
				'instanceid' => $this->config->getSystemValueString('instanceid'),
				'dbtype' => $this->config->getSystemValueString('dbtype'),
				'apps' => $this->installedApps()],
		);

		$run = new Run();
		$run->setTargetId($target->getId());
		$run->setKind(self::KIND_FULL);
		$run->setStatus(Run::RUNNING);
		$run->setPhase('db');
		$run->setState(json_encode(['files' => $files], JSON_THROW_ON_ERROR));
		$run->setSnapshotId($files['snapshot']);
		$run->setStartedAt(time());
		$run->setUpdatedAt(time());
		return $this->runs->insert($run);
	}

	/**
	 * Do one step of a run until $deadline. Safe to call concurrently: a second caller finds the
	 * run locked and returns immediately.
	 */
	public function step(Run $run, float $deadline): Run {
		// Leases expire on their own: a step killed by a time limit or OOM blocks nothing for long.
		$ttl = (int)min(3600, max(120, $deadline - microtime(true) + 120));
		try {
			$runLease = $this->leases->acquire('run/' . $run->getId(), LeaseService::EXCLUSIVE, $ttl);
		} catch (LockedException) {
			return $run; // another process is doing this step
		}
		// Shared lease on the location while this step writes: a cleanup (exclusive) cannot run now.
		try {
			$targetLease = $this->leases->acquire('ng_backup/target/' . $run->getTargetId(), LeaseService::SHARED, $ttl);
		} catch (LockedException) {
			$this->leases->release($runLease);
			return $run;
		}
		try {
			$run = $this->runs->find($run->getId()); // fresh state after acquiring the lock
			if ($run->getStatus() !== Run::RUNNING || $run->getKind() !== self::KIND_FULL) {
				return $run;
			}
			$target = $this->targets->get((string)$run->getTargetId());
			$repo = $this->targets->repository($target);
			$state = json_decode($run->getState(), true, 512, JSON_THROW_ON_ERROR);

			if ($run->getPhase() === 'db') {
				$dumper = new DbDumper($this->db->getInner(), $this->config->getSystemValueString('dbtableprefix', 'oc_'));
				$dbStats = $state['dbStats'] ?? [];
				// With system cron the whole dump usually fits in one step (one transaction, fully
				// consistent). Under web cron it continues per step; the snapshot then says so.
				$dump = $dumper->dumpStep($repo, $state['dump'] ?? $dumper->startDump(), $deadline, $dbStats);
				$state['dbStats'] = $dbStats;
				if ($dump['done']) {
					$manifest = $dumper->manifest($dump);
					$repo->putObject('db/' . $state['files']['snapshot'], json_encode($manifest, JSON_THROW_ON_ERROR));
					$state['files']['meta']['db'] = 'db/' . $state['files']['snapshot'];
					$state['files']['meta']['dbConsistent'] = $manifest['consistent'];
					$state['dbStats'] += ['tables' => count($manifest['tables']), 'rows' => array_sum(array_column($manifest['tables'], 'rows')),
						'steps' => $dump['steps'], 'consistent' => $manifest['consistent']];
					unset($state['dump']);
					$run->setPhase('files');
				} else {
					$state['dump'] = $dump;
				}
			} else {
				$state['files'] = BackupRun::step($repo, $state['files'], $deadline);
				if ($state['files']['phase'] === 'done') {
					$this->recordSnapshot($run, $state['files']);
					$this->alerts->storedBytes($target, $repo->storedBytes());
					$run->setStatus(Run::DONE);
					$run->setPhase('done');
					$run->setFinishedAt(time());
				}
			}
			$run->setState(json_encode($state, JSON_THROW_ON_ERROR));
			$run->setStats(json_encode(['db' => $state['dbStats'] ?? null, 'files' => $state['files']['stats']], JSON_THROW_ON_ERROR));
			$run->setUpdatedAt(time());
			return $this->runs->update($run);
		} catch (\Throwable $e) {
			$this->logger->error('NG Backup run ' . $run->getId() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			$run->setStatus(Run::FAILED);
			$run->setError($e->getMessage());
			$run->setFinishedAt(time());
			$run->setUpdatedAt(time());
			$run = $this->runs->update($run);
			try {
				$this->alerts->runFailed($run, isset($target) ? $target->getName() : '#' . $run->getTargetId());
			} catch (\Throwable) {
			}
			return $run;
		} finally {
			$this->leases->release($targetLease);
			$this->leases->release($runLease);
		}
	}

	/** Run to completion in this process (occ, system cron), reporting progress. */
	public function runToCompletion(Run $run, ?callable $progress = null, float $stepSeconds = 30): Run {
		while ($run->getStatus() === Run::RUNNING) {
			$before = $run->getUpdatedAt();
			$run = $this->step($run, microtime(true) + $stepSeconds);
			if ($run->getStatus() === Run::RUNNING && $run->getUpdatedAt() === $before) {
				// Step was not possible (another process holds the run, or a crashed step's lease has
				// not expired yet): wait instead of spinning.
				sleep(5);
				$run = $this->runs->find($run->getId());
			}
			if ($progress !== null) {
				$progress($run);
			}
		}
		return $run;
	}

	public function cancel(Run $run): Run {
		$run->setStatus(Run::CANCELLED);
		$run->setFinishedAt(time());
		$run->setUpdatedAt(time());
		return $this->runs->update($run);
	}

	private function recordSnapshot(Run $run, array $files): void {
		$s = new Snapshot();
		$s->setTargetId($run->getTargetId());
		$s->setSnapshotId($files['snapshot']);
		$s->setKind($run->getKind());
		$s->setLabel($files['label'] !== '' ? $files['label'] : null);
		$s->setCreatedAt(time());
		$s->setFiles($files['stats']['files']);
		$s->setBytes($files['stats']['bytes']);
		$this->snapshots->insert($s);
	}

	/** @return list<string> */
	private function excludes(string $dataDir): array {
		$instanceId = $this->config->getSystemValueString('instanceid');
		$exclude = [
			"data/appdata_$instanceId/preview",
			"data/updater-$instanceId",
			'data/nextcloud.log',
			'data/nextcloud.log.*',
			'data/audit.log',
			'data/*/cache',
			'data/*/uploads',
		];
		// SQLite: the database file is in the data directory; it is backed up by the logical dump,
		// and copying the live file would give an inconsistent copy.
		if ($this->config->getSystemValueString('dbtype') === 'sqlite3') {
			$dbName = $this->config->getSystemValueString('dbname', 'owncloud');
			$exclude[] = "data/$dbName.db";
			$exclude[] = "data/$dbName.db-journal";
			$exclude[] = "data/$dbName.db-wal";
			$exclude[] = "data/$dbName.db-shm";
		}
		$logFile = $this->config->getSystemValueString('logfile', '');
		if ($logFile !== '' && str_starts_with($logFile, $dataDir . '/')) {
			$exclude[] = 'data/' . substr($logFile, strlen($dataDir) + 1);
		}
		// Never back up a local backup location into itself.
		foreach ($this->targets->list() as $t) {
			$opts = $this->targets->options($t);
			$dir = rtrim((string)($opts['datadir'] ?? ''), '/');
			if ($t->getBackend() === 'local' && $dir !== '' && str_starts_with($dir . '/', $dataDir . '/')) {
				$exclude[] = trim('data/' . substr($dir, strlen($dataDir) + 1) . '/' . $t->getBasePath(), '/');
			}
		}
		return array_values(array_unique($exclude));
	}

	/** @return array<string, string> app id => version */
	private function installedApps(): array {
		$apps = [];
		foreach ($this->appManager->getEnabledApps() as $app) {
			$apps[$app] = $this->appManager->getAppVersion($app);
		}
		ksort($apps);
		return $apps;
	}
}
