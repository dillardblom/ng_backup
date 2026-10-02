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
		private KeyService $keys,
		private LeaseService $leases,
	) {
	}

	public function untrash(Target $target, string $snapshotId): void {
		$lease = $this->lockExclusive($target, 300);
		try {
			$this->doUntrash($target, $snapshotId);
		} finally {
			$this->leases->release($lease);
		}
	}

	/**
	 * Run $fn under the location's exclusive lock (to create a run atomically with respect to a
	 * cleanup, which takes the same lock and then refuses while a run exists).
	 */
	public function underStartLock(Target $target, callable $fn): mixed {
		try {
			return $this->leases->with(self::lockKey($target), LeaseService::EXCLUSIVE, 60, fn () => $fn());
		} catch (LockedException) {
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is in progress; try again later');
		}
	}

	/**
	 * Exclusive lock on the location, then make sure no run of any kind (backup, restore, export)
	 * is in progress: their data may not be referenced by a snapshot yet, or is being read.
	 */
	private function lockExclusive(Target $target, int $ttl): string {
		try {
			$lease = $this->leases->acquire(self::lockKey($target), LeaseService::EXCLUSIVE, $ttl);
		} catch (LockedException) {
			throw new \RuntimeException('A backup, restore or cleanup of ' . $target->getName() . ' is in progress; try again later');
		}
		if ($this->runs->findRunning($target->getId()) !== []) {
			$this->leases->release($lease);
			throw new \RuntimeException('A backup or restore of ' . $target->getName() . ' is running; try again later');
		}
		return $lease;
	}

	private function doUntrash(Target $target, string $snapshotId): void {
		$repo = $this->targets->repository($target);
		if (!array_key_exists($snapshotId, $repo->trash())) {
			throw new \InvalidArgumentException('That snapshot is not in the trash (or was already deleted)');
		}
		$repo->untrash($snapshotId);
		// Put it back in the local snapshot list as well.
		$meta = $repo->snapshotMeta($snapshotId);
		$s = new \OCA\NgBackup\Db\Snapshot();
		$s->setTargetId($target->getId());
		$s->setSnapshotId($snapshotId);
		$s->setKind((string)($meta['kind'] ?? 'full'));
		$s->setLabel(($meta['label'] ?? '') !== '' ? $meta['label'] : null);
		$s->setCreatedAt((int)strtotime((string)($meta['time'] ?? 'now')));
		$s->setFiles((int)($meta['stats']['files'] ?? 0));
		$s->setBytes((int)($meta['stats']['bytes'] ?? 0));
		try {
			$this->snapshots->insert($s);
		} catch (\Throwable) {
		}
		$this->alerts->securityEvent('snapshot_untrashed', ['target' => $target->getName(), 'snapshot' => $snapshotId]);
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
	 * Snapshots the policy drops go to the trash on the location; trash entries older than the
	 * deletion delay are purged, and only then is their data freed.
	 *
	 * @return array{kept: array<string, list<string>>, forgotten: list<string>, purged: list<string>, prune: array}
	 */
	public function apply(Target $target, bool $dryRun = false, bool $scheduled = false): array {
		if ($target->getAppendOnly()) {
			throw new \RuntimeException('Location ' . $target->getName() . ' is append-only: NG Backup does not delete anything there');
		}
		$lease = $this->lockExclusive($target, 1800);
		try {
			$repo = $this->targets->repository($target);
			$times = [];
			foreach ($repo->listSnapshots() as $id) {
				$meta = $repo->snapshotMeta($id);
				$times[$id] = (int)strtotime((string)($meta['time'] ?? '@0'));
			}
			$keep = $this->policy()->keep($times);
			$forget = array_values(array_diff(array_keys($times), array_keys($keep)));
			$actor = $scheduled ? 'retention policy' : $this->alerts->actor();
			$purged = [];
			if (!$dryRun) {
				foreach ($forget as $id) {
					$repo->forget($id, $actor);
					try {
						$this->snapshots->delete($this->snapshots->findOne($target->getId(), $id));
					} catch (\Throwable) {
					}
				}
			}
			// Also removes leftovers of interrupted runs. A dry run counts the snapshots it would forget as gone.
			$delay = $this->keys->deleteDelayDays();
			if (!$dryRun && $forget !== []) {
				// Scheduled cleanup follows the policy: audit log only, no notification every week.
				$this->alerts->securityEvent('snapshots_forgotten', ['target' => $target->getName(), 'count' => (string)count($forget),
					'until' => date('Y-m-d', time() + $delay * 86400)], !$scheduled);
			}
			if (!$dryRun) {
				$purged = $repo->purgeTrash($delay * 86400);
			}
			$prune = $repo->prune($dryRun);
			return ['kept' => $keep, 'forgotten' => $forget, 'purged' => $purged, 'prune' => $prune];
		} finally {
			$this->leases->release($lease);
		}
	}
}
