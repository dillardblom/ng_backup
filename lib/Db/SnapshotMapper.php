<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Snapshot> */
class SnapshotMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ngb_snapshots', Snapshot::class);
	}

	/** @return list<Snapshot> newest first */
	public function findForTarget(int $targetId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function findOne(int $targetId, string $snapshotId): Snapshot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('snapshot_id', $qb->createNamedParameter($snapshotId)));
		return $this->findEntity($qb);
	}

	public function latest(int $targetId, string $kind): ?Snapshot {
		foreach ($this->findForTarget($targetId) as $s) {
			if ($s->getKind() === $kind) {
				return $s;
			}
		}
		return null;
	}
}
