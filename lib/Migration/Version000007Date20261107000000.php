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

/** Local copy of what the catalog on the location says about a snapshot: its note and pin. */
final class Version000007Date20261107000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('ngb_snapshots');
		$changed = false;
		if (!$t->hasColumn('note')) {
			$t->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$changed = true;
		}
		if (!$t->hasColumn('pinned')) {
			$t->addColumn('pinned', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
