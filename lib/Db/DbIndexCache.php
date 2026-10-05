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
		$qb = $this->db->getQueryBuilder();
		$qb->insert('ngb_index')->values([
			'repository_id' => $qb->createNamedParameter($repositoryId),
			'pack_id' => $qb->createNamedParameter($packId),
			'entries' => $qb->createNamedParameter(json_encode($entries, JSON_THROW_ON_ERROR)),
		]);
		try {
			$qb->executeStatement();
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
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
