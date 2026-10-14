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

	/**
	 * Write $run only if it is still running and unchanged since it was loaded (updated_at
	 * $loadedAt), in one statement: two processes that loaded the same run cannot both write it.
	 * The new updated_at is always later than $loadedAt, so every write is seen as a change.
	 *
	 * @return Run|null the written run, or null when another process wrote it first
	 */
	public function updateIfUnchanged(Run $run, int $loadedAt): ?Run {
		$run->setUpdatedAt(max(time(), $loadedAt + 1));
		$types = $run->getFieldTypes();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach (array_keys($run->getUpdatedFields()) as $field) {
			if ($field === 'id') {
				continue;
			}
			$value = $run->{'get' . ucfirst($field)}();
			$type = ($types[$field] ?? 'string') === 'integer' ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
			$qb->set($run->propertyToColumn($field), $qb->createNamedParameter($value, $value === null ? IQueryBuilder::PARAM_NULL : $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($run->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Run::RUNNING)))
			->andWhere($qb->expr()->eq('updated_at', $qb->createNamedParameter($loadedAt, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return null;
		}
		$run->resetUpdatedFields();
		return $run;
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

	/**
	 * The runs to list, newest first: those started at or after $since, those still running, and
	 * at least the newest $atLeast, so the list is never empty after a quiet week. All runs when
	 * $since is null.
	 *
	 * @return list<Run>
	 */
	public function findShown(?int $since, int $atLeast = 5): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'DESC');
		if ($since === null) {
			return $this->findEntities($qb);
		}
		$qb->where($qb->expr()->orX(
			$qb->expr()->gte('started_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('status', $qb->createNamedParameter(Run::RUNNING)),
		));
		$runs = [];
		foreach ([...$this->findEntities($qb), ...$this->findRecent($atLeast)] as $r) {
			$runs[$r->getId()] = $r;
		}
		krsort($runs);
		return array_values($runs);
	}

	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/** @return list<Run> runs of $kind started at or after $since, newest first */
	public function startedSince(int $targetId, string $kind, int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('target_id', $qb->createNamedParameter($targetId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
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
