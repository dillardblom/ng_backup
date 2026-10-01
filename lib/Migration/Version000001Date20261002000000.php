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

class Version000001Date20261002000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ngb_targets')) {
			$t = $schema->createTable('ngb_targets');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('backend', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('auth', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Backend/auth options as JSON, encrypted with ICrypto (they contain credentials).
			$t->addColumn('options', Types::TEXT, ['notnull' => true]);
			$t->addColumn('base_path', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			$t->addColumn('repository_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['name'], 'ngb_targets_name');
		}

		if (!$schema->hasTable('ngb_runs')) {
			$t = $schema->createTable('ngb_runs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('phase', Types::STRING, ['notnull' => true, 'length' => 32]);
			// Resumable state (JSON). Large lists live in the repository, not here.
			$t->addColumn('state', Types::TEXT, ['notnull' => true]);
			$t->addColumn('stats', Types::TEXT, ['notnull' => false]);
			$t->addColumn('error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('snapshot_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('finished_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['target_id', 'status'], 'ngb_runs_target_status');
		}

		if (!$schema->hasTable('ngb_snapshots')) {
			// Cache of the snapshots in each repository, for listing without reading the target.
			$t = $schema->createTable('ngb_snapshots');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('target_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('snapshot_id', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('files', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('bytes', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['target_id', 'snapshot_id'], 'ngb_snap_target_snap');
		}

		return $schema;
	}
}
