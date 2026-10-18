<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Removing a location left the cached index of its repository behind (tens of thousands of rows
 * for a large backup). Drop the cache of repositories no location uses any more.
 */
final class Version000006Date20261018000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$used = $this->db->getQueryBuilder();
		$used->select('repository_id')->from('ngb_targets')->where($used->expr()->isNotNull('repository_id'));
		$keep = array_column($used->executeQuery()->fetchAll(), 'repository_id');
		$delete = $this->db->getQueryBuilder();
		$delete->delete('ngb_index');
		if ($keep !== []) {
			$delete->where($delete->expr()->notIn('repository_id', $delete->createNamedParameter($keep, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$removed = $delete->executeStatement();
		if ($removed > 0) {
			$output->info("Removed $removed cached index rows of locations that no longer exist");
		}
	}
}
