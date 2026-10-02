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

/** Leases with an expiry: a killed process cannot block a location for longer than its lease. */
class Version000005Date20261002040000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('ngb_leases')) {
			return null;
		}
		$t = $schema->createTable('ngb_leases');
		$t->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 32]);
		$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
		$t->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 1]);
		$t->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['name'], 'ngb_leases_name');
		return $schema;
	}
}
