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

/** Optional size limit per location (cost control; S3 has no bucket quota). */
final class Version000004Date20261002030000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('ngb_targets');
		if (!$t->hasColumn('max_bytes')) {
			$t->addColumn('max_bytes', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			return $schema;
		}
		return null;
	}
}
