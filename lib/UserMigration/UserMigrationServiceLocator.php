<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\UserMigration;

use OCP\App\IAppManager;
use OCP\Server;
use OCP\UserMigration\UserMigrationException;

/**
 * Resolves user_migration's own (internal, non-public) UserMigrationService on demand.
 *
 * Unlike files_external, user_migration is an optional dependency: without it, only per-user
 * backup/restore is unavailable, the full backup still works. The class is never autoloaded by
 * composer; everything that touches it is behind this one class.
 */
final class UserMigrationServiceLocator {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/** @throws UserMigrationException when user_migration is not enabled anywhere */
	public function get(): object {
		if (!$this->appManager->isEnabledForAnyone('user_migration')) {
			throw new UserMigrationException('The user_migration app is not enabled; per-user backup/restore is unavailable');
		}
		$this->appManager->loadApp('user_migration');
		return Server::get(\OCA\UserMigration\Service\UserMigrationService::class);
	}
}
