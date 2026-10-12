<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCA\NgBackup\Repository\IIndexCache;
use OCP\DB\Exception;
use OCP\IDBConnection;

final class DbIndexCache implements IIndexCache {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function all(string $repositoryId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('pack_id', 'entries')->from('ngb_index')->where($qb->expr()->eq('repository_id', $qb->createNamedParameter($repositoryId)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[$row['pack_id']] = json_decode($row['entries'], true, 512, JSON_THROW_ON_ERROR);
		}
		$result->closeCursor();
		return $out;
	}

	public function put(string $repositoryId, string $packId, array $entries): void {
		try {
			$this->insert($repositoryId, $packId, $entries);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * In one transaction: on PostgreSQL with its default settings every statement of its own
	 * waits for a disk sync, which made 27,547 rows take more than 6 minutes. Rows already there
	 * are replaced. Should the transaction fail (another process writing the same rows), the
	 * rows are written one by one, keeping rows that are already there.
	 */
	public function putMany(string $repositoryId, array $packs): void {
		if ($packs === []) {
			return;
		}
		try {
			$this->transaction(function () use ($repositoryId, $packs): void {
				$this->remove($repositoryId, array_keys($packs));
				$this->insertAll($repositoryId, $packs);
			});
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				throw $e; // a transaction of the caller's, which may be spoilt now
			}
			foreach ($packs as $packId => $entries) {
				$this->put($repositoryId, $packId, $entries);
			}
		}
	}

	/** All or nothing: when it fails, the cache stays as it was. */
	public function replaceAll(string $repositoryId, array $packs): void {
		$this->transaction(function () use ($repositoryId, $packs): void {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ngb_index')->where($qb->expr()->eq('repository_id', $qb->createNamedParameter($repositoryId)));
			$qb->executeStatement();
			$this->insertAll($repositoryId, $packs);
		});
	}

	/** Run $write in a transaction of its own, or as part of the caller's when there is one. */
	private function transaction(\Closure $write): void {
		if ($this->db->inTransaction()) {
			$write();
			return;
		}
		$this->db->beginTransaction();
		try {
			$write();
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @param array<string, list<array{id:string, offset:int, length:int, raw:int, flags:int}>> $packs */
	private function insertAll(string $repositoryId, array $packs): void {
		foreach ($packs as $packId => $entries) {
			$this->insert($repositoryId, $packId, $entries);
		}
	}

	/** @param list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries */
	private function insert(string $repositoryId, string $packId, array $entries): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('ngb_index')->values([
			'repository_id' => $qb->createNamedParameter($repositoryId),
			'pack_id' => $qb->createNamedParameter($packId),
			'entries' => $qb->createNamedParameter(json_encode($entries, JSON_THROW_ON_ERROR)),
		]);
		$qb->executeStatement();
	}

	public function remove(string $repositoryId, array $packIds): void {
		foreach (array_chunk($packIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ngb_index')
				->where($qb->expr()->eq('repository_id', $qb->createNamedParameter($repositoryId)))
				->andWhere($qb->expr()->in('pack_id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}
}
