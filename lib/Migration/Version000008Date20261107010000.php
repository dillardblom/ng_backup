<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Migration;

use Closure;
use OCA\NgBackup\Service\LocationSchedule;
use OCA\NgBackup\Service\Schedule;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** A schedule per location. Each existing location gets the daily time that held for all of them. */
final class Version000008Date20261107010000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private Schedule $schedule,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('ngb_targets');
		if ($t->hasColumn('schedule')) {
			return null;
		}
		$t->addColumn('schedule', Types::STRING, ['notnull' => false, 'length' => 16]);
		$t->addColumn('schedule_zone', Types::STRING, ['notnull' => false, 'length' => 64]);
		$t->addColumn('schedule_since', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		['time' => $time, 'timezone' => $zone] = $this->schedule->get();
		$qb = $this->db->getQueryBuilder();
		$qb->update('ngb_targets')
			->set('schedule', $qb->createNamedParameter(Schedule::validTime($time) ? LocationSchedule::normalize($time) : ''))
			->set('schedule_zone', $qb->createNamedParameter($zone))
			->where($qb->expr()->isNull('schedule'));
		$qb->executeStatement();
	}
}
