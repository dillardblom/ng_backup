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
class BackupService {
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
	) {
	}

	public function start(Target $target, string $label = ''): Run {
		if (!$this->keys->everConfirmed()) {
			throw new \RuntimeException('Confirm the recovery kit first (occ backup:key:confirm)');
		}
		if ($this->config->getSystemValue('objectstore', null) !== null) {
			throw new \RuntimeException('Object storage as primary storage is not supported yet');
		}
		if ($this->locking->isLocked(PruneService::lockKey($target), ILockingProvider::LOCK_EXCLUSIVE)) {
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is running; try again later');
		}
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
		$lockKey = 'ng_backup/run/' . $run->getId();
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
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
				@set_time_limit(0); // the dump is one transaction; it cannot be split across requests yet
				$dbStats = [];
				$manifest = (new DbDumper($this->db->getInner(), $this->config->getSystemValueString('dbtableprefix', 'oc_')))->dump($repo, $dbStats);
				$repo->putObject('db/' . $state['files']['snapshot'], json_encode($manifest, JSON_THROW_ON_ERROR));
				$state['files']['meta']['db'] = 'db/' . $state['files']['snapshot'];
				$state['dbStats'] = $dbStats + ['tables' => count($manifest['tables']),
					'rows' => array_sum(array_column($manifest['tables'], 'rows'))];
				$run->setPhase('files');
			} else {
				$state['files'] = BackupRun::step($repo, $state['files'], $deadline);
				if ($state['files']['phase'] === 'done') {
					$this->recordSnapshot($run, $state['files']);
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
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Run to completion in this process (occ, system cron), reporting progress. */
	public function runToCompletion(Run $run, ?callable $progress = null, float $stepSeconds = 30): Run {
		while ($run->getStatus() === Run::RUNNING) {
			$run = $this->step($run, microtime(true) + $stepSeconds);
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
