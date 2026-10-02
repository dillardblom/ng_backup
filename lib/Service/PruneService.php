<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Db\SnapshotMapper;
use OCA\NgBackup\Db\Target;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Applies the retention policy to a location: forgets snapshots the policy does not keep and
 * prunes data no remaining snapshot uses. Never runs on append-only locations, never while a
 * backup to the same location is running (the location lock is shared with BackupService).
 */
class PruneService {
	public function __construct(
		private TargetService $targets,
		private RunMapper $runs,
		private SnapshotMapper $snapshots,
		private IAppConfig $appConfig,
		private ILockingProvider $locking,
		private AlertService $alerts,
	) {
	}

	public static function lockKey(Target $target): string {
		return 'ng_backup/target/' . $target->getId();
	}

	public function policy(): RetentionPolicy {
		return RetentionPolicy::fromArray(json_decode($this->appConfig->getValueString(Application::APP_ID, 'retention', '{}'), true) ?: []);
	}

	public function setPolicy(RetentionPolicy $policy): void {
		if ($policy->last < 1) {
			throw new \InvalidArgumentException('Keep at least the last snapshot');
		}
		$old = $this->policy()->toArray();
		$this->appConfig->setValueString(Application::APP_ID, 'retention', json_encode($policy->toArray(), JSON_THROW_ON_ERROR));
		if ($old !== $policy->toArray()) {
			$this->alerts->securityEvent('retention_changed', ['policy' => json_encode($policy->toArray())]);
		}
	}

	/**
	 * @return array{kept: array<string, list<string>>, forgotten: list<string>, prune: array}
	 */
	public function apply(Target $target, bool $dryRun = false, bool $scheduled = false): array {
		if ($target->getAppendOnly()) {
			throw new \RuntimeException('Location ' . $target->getName() . ' is append-only: NG Backup does not delete anything there');
		}
		if ($this->runs->findRunning($target->getId(), BackupService::KIND_FULL) !== []) {
			throw new \RuntimeException('A backup to ' . $target->getName() . ' is running; try again later');
		}
		try {
			$this->locking->acquireLock(self::lockKey($target), ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new \RuntimeException('Another backup or cleanup of ' . $target->getName() . ' is running');
		}
		try {
			$repo = $this->targets->repository($target);
			$times = [];
			foreach ($repo->listSnapshots() as $id) {
				$meta = $repo->snapshotMeta($id);
				$times[$id] = (int)strtotime((string)($meta['time'] ?? '@0'));
			}
			$keep = $this->policy()->keep($times);
			$forget = array_values(array_diff(array_keys($times), array_keys($keep)));
			if (!$dryRun) {
				foreach ($forget as $id) {
					$repo->forget($id);
					try {
						$this->snapshots->delete($this->snapshots->findOne($target->getId(), $id));
					} catch (\Throwable) {
					}
				}
			}
			// Also removes leftovers of interrupted runs. A dry run counts the snapshots it would forget as gone.
			if (!$dryRun && $forget !== []) {
				// Scheduled cleanup follows the policy: audit log only, no notification every week.
				$this->alerts->securityEvent('snapshots_forgotten', ['target' => $target->getName(), 'count' => (string)count($forget)], !$scheduled);
			}
			$prune = $repo->prune($dryRun, 0.5, $dryRun ? $forget : []);
			return ['kept' => $keep, 'forgotten' => $forget, 'prune' => $prune];
		} finally {
			$this->locking->releaseLock(self::lockKey($target), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
