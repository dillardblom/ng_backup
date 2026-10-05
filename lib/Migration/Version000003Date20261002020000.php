<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Append-only locations: NG Backup never deletes anything there (cleanup by the location's own lifecycle rules). */
final class Version000003Date20261002020000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('ngb_targets');
		if (!$t->hasColumn('append_only')) {
			$t->addColumn('append_only', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			return $schema;
		}
		return null;
	}
}
