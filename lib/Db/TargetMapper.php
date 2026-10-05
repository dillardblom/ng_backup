<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Target> */
final class TargetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ngb_targets', Target::class);
	}

	public function find(int $id): Target {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByName(string $name): Target {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		return $this->findEntity($qb);
	}

	/** @return list<Target> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name');
		return $this->findEntities($qb);
	}
}
