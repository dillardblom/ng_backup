<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Migration;

use Closure;
use OCA\NgBackup\Service\KeyService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A key from before 1.1.0 counts as used: those versions did not keep track, and it may protect
 * backups on a location that was removed from this server later (user exports included). Only a
 * key made with 1.1.0 or newer can be replaced as an unused one.
 */
final class Version000009Date20261108000000 extends SimpleMigrationStep {
	public function __construct(
		private KeyService $keys,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->keys->isInitialized()) {
			$this->keys->markUsed();
		}
	}
}
