<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Run> */
final class RunMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ngb_runs', Run::class);
	}

	public function find(int $id): Run {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<Run> */
	public function findRunning(?int $targetId = null, ?string $kind = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('status', $qb->createNamedParameter(Run::RUNNING)));
		if ($kind !== null) {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		if ($targetId !== null) {
			$qb->andWhere($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb->orderBy('id'));
	}

	/** @return list<Run> */
	public function findRecent(int $limit = 20): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function lastFinished(int $targetId, string $kind): ?Run {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Run::DONE)))
			->orderBy('id', 'DESC')->setMaxResults(1);
		$r = $this->findEntities($qb);
		return $r[0] ?? null;
	}
}
