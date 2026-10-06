<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\Target;
use OCA\NgBackup\UserMigration\RepositoryExportDestination;
use OCA\NgBackup\UserMigration\RepositoryImportSource;
use OCA\NgBackup\UserMigration\UserMigrationServiceLocator;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\FilesystemTornDownEvent;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use OCP\Security\ISecureRandom;
use OCP\UserMigration\UserMigrationException;

/**
 * Restoring a user from a user_migration export (see UserBackupService), never over an existing
 * account:
 *  - replace: a safety export of the current state goes into the repository first, then the
 *    account is deleted and recreated fresh from the backup, under the same uid;
 *  - as-backup: the backup is restored into a new account "<uid>-bak" (or "-bak-2", ...) instead,
 *    so an admin can compare and transfer by hand. Nextcloud cannot rename a uid, so the *backup*
 *    gets the new name, never the existing account.
 */
final class UserRestoreService {
	public const MODE_REPLACE = 'replace';
	public const MODE_AS_BACKUP = 'as-backup';

	public function __construct(
		private TargetService $targets,
		private LeaseService $leases,
		private UserMigrationServiceLocator $locator,
		private IUserManager $userManager,
		private ISecureRandom $random,
		private IEventDispatcher $events,
	) {
	}

	/** @throws UserMigrationException when user_migration is not enabled, or the restore itself fails */
	public function restoreUser(Target $target, string $manifestPath, string $mode = self::MODE_REPLACE): IUser {
		$service = $this->locator->get();
		return $this->withSharedLock($target, function () use ($target, $manifestPath, $mode, $service): IUser {
			$repo = $this->targets->repository($target);
			// Only ever read a manifest the verified catalog actually recorded: a path alone
			// (even one this app generated before) proves nothing about it still being current
			// or genuine once the catalog's rollback protection is supposed to vouch for it.
			if (!$repo->isRecordedUserExport($manifestPath)) {
				throw new \InvalidArgumentException("$manifestPath is not a recorded user export for this location");
			}
			$source = new RepositoryImportSource($repo, $manifestPath);
			$originalUid = $source->getOriginalUid();

			if ($mode === self::MODE_AS_BACKUP) {
				$backupUid = $this->freeBackupUid($originalUid);
				$user = $this->userManager->createUser($backupUid, $this->random->generate(32));
				if ($user === false) {
					throw new \RuntimeException("Could not create the account $backupUid");
				}
				try {
					return $service->import($source, $user);
				} catch (\Throwable $e) {
					// A partially-imported, never-used account left behind is strictly worse
					// than no account at all; the original source account was never touched.
					$user->delete();
					throw $e;
				}
			}

			if ($mode !== self::MODE_REPLACE) {
				throw new \InvalidArgumentException("Unknown restore mode: $mode");
			}
			$existing = $this->userManager->get($originalUid);
			$safetyManifestPath = null;
			if ($existing !== null) {
				// Never import over a live account: export its current state first, so nothing
				// is lost if the restore turns out to be the wrong choice.
				$safety = new RepositoryExportDestination($repo, $originalUid);
				$service->export($safety, $existing);
				$safetyManifestPath = $safety->manifestPath();
				// Same race as UserBackupService::backupUser(): reopen the repository inside the
				// catalog lock so the write builds on the latest generation.
				$this->withCatalogLock($target, fn () => $this->targets->repository($target)->recordUserExport($safetyManifestPath));
				if (!$existing->delete()) {
					throw new \RuntimeException("Could not delete the existing account $originalUid before restoring; its safety export is at $safetyManifestPath");
				}
				// IRootFolder caches a Folder per uid for the lifetime of this process; without
				// this it would still hand the just-deleted account's (now storage-less) folder
				// to the import below, and every write would fail.
				$this->events->dispatchTyped(new FilesystemTornDownEvent());
			}
			try {
				return $service->import($source, null);
			} catch (\Throwable $e) {
				// The original account is already gone at this point (or never existed); a
				// failure here can only be reported, not undone in place. Its safety export
				// (if any) is the recovery path, already recorded above.
				throw new UserMigrationException(
					"Could not restore $originalUid" . ($safetyManifestPath !== null ? "; its safety export is at $safetyManifestPath" : '') . ': ' . $e->getMessage(),
					0,
					$e,
				);
			}
		});
	}

	private function freeBackupUid(string $uid): string {
		$candidate = $uid . '-bak';
		for ($n = 2; $this->userManager->userExists($candidate); $n++) {
			$candidate = $uid . '-bak-' . $n;
		}
		return $candidate;
	}

	private function withSharedLock(Target $target, callable $fn): mixed {
		try {
			// See UserBackupService::withSharedLock(): UserMigrationService exposes no progress,
			// so this lease cannot be refreshed mid-call.
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
