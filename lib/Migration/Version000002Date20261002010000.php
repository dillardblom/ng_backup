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

/** Local cache of the per-pack index files, so opening a repository does not download them all. */
class Version000002Date20261002010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ngb_index')) {
			$t = $schema->createTable('ngb_index');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('repository_id', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('pack_id', Types::STRING, ['notnull' => true, 'length' => 32]);
			// Plain JSON list of blob entries. Blob ids are keyed hashes and reveal no content.
			$t->addColumn('entries', Types::TEXT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['repository_id', 'pack_id'], 'ngb_index_repo_pack');
		}
		return $schema;
	}
}
