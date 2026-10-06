<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\Target;
use OCA\NgBackup\UserMigration\RepositoryExportDestination;
use OCA\NgBackup\UserMigration\UserMigrationServiceLocator;
use OCP\IUser;
use OCP\Lock\LockedException;
use OCP\UserMigration\UserMigrationException;

/**
 * Per-user backup via user_migration: every registered migrator's export streams straight into
 * the encrypted repository (no zip, no copy in the user's own storage, see
 * RepositoryExportDestination). Shares blobs with the full backup; prune() keeps a user export's
 * blobs referenced through its manifest under users/<uid>/.
 *
 * user_migration is an optional dependency (unlike files_external): without it, only this
 * per-user backup/restore is unavailable, the full backup still works.
 */
final class UserBackupService {
	public function __construct(
		private TargetService $targets,
		private LeaseService $leases,
		private UserMigrationServiceLocator $locator,
	) {
	}

	/** @throws UserMigrationException when user_migration is not enabled, or the export itself fails */
	public function backupUser(Target $target, IUser $user): string {
		$service = $this->locator->get();
		return $this->withSharedLock($target, function (callable $refresh) use ($target, $user, $service) {
			$repo = $this->targets->repository($target);
			$dest = new RepositoryExportDestination($repo, $user->getUID(), LeaseService::throttled($refresh));
			$service->export($dest, $user);
			// A short exclusive lock around the catalog write only: the shared lock above
			// allows another concurrent backup (full or per-user) to be writing the catalog at
			// the same time. The repository is reopened inside the lock, so the write builds on
			// the latest catalog generation instead of the one loaded before the lock was taken.
			$this->withCatalogLock($target, fn () => $this->targets->repository($target)->recordUserExport($dest->manifestPath()));
			return $dest->manifestPath();
		});
	}

	private function withSharedLock(Target $target, callable $fn): mixed {
		try {
			// Refreshed by RepositoryExportDestination as it writes data, so a long export keeps it.
			return $this->leases->with(PruneService::lockKey($target), LeaseService::SHARED, 3600, $fn);
		} catch (LockedException) {
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is in progress; try again later');
		}
	}

	private function withCatalogLock(Target $target, callable $fn): mixed {
		try {
			return $this->leases->with(PruneService::catalogLockKey($target), LeaseService::EXCLUSIVE, 30, $fn);
		} catch (LockedException) {
			throw new \RuntimeException('Another catalog write for ' . $target->getName() . ' is in progress; try again');
		}
	}
}
