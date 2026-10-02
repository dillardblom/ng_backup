<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Shared/exclusive leases with an expiry, stored in ngb_leases.
 *
 * Nextcloud's locks live up to an hour; a PHP process killed while holding one (web cron time
 * limit, OOM, kill -9) would block a location that long. A lease expires on its own unless its
 * holder keeps refreshing it. Nextcloud's lock is only held for the few milliseconds in which a
 * lease is checked and written.
 */
class LeaseService {
	public const SHARED = 's';
	public const EXCLUSIVE = 'x';

	public function __construct(
		private IDBConnection $db,
		private ILockingProvider $locking,
	) {
	}

	/** @return string lease id; throws LockedException when it conflicts with a live lease */
	public function acquire(string $name, string $mode, int $ttl): string {
		$guard = 'ng_backup/lease/' . $name;
		$this->locking->acquireLock($guard, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$now = time();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('ngb_leases')
				->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
				->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($now)));
			$qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->select('mode')->from('ngb_leases')->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));
			$modes = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
			if ($modes !== [] && ($mode === self::EXCLUSIVE || in_array(self::EXCLUSIVE, $modes, true))) {
				throw new LockedException($name);
			}
			$id = bin2hex(random_bytes(16));
			$qb = $this->db->getQueryBuilder();
			$qb->insert('ngb_leases')->values([
				'id' => $qb->createNamedParameter($id),
				'name' => $qb->createNamedParameter($name),
				'mode' => $qb->createNamedParameter($mode),
				'expires_at' => $qb->createNamedParameter($now + $ttl),
			]);
			$qb->executeStatement();
			return $id;
		} finally {
			$this->locking->releaseLock($guard, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Extend a lease while its holder is still working. */
	public function refresh(string $id, int $ttl): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('ngb_leases')->set('expires_at', $qb->createNamedParameter(time() + $ttl))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	public function release(string $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('ngb_leases')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}

	/** Run $fn under a lease; $refresh() can be called during long work. */
	public function with(string $name, string $mode, int $ttl, callable $fn): mixed {
		$id = $this->acquire($name, $mode, $ttl);
		try {
			return $fn(fn () => $this->refresh($id, $ttl));
		} finally {
			$this->release($id);
		}
	}
}
