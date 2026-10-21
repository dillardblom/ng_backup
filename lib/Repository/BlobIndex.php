<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Crypto\StreamCipher;

/**
 * Which blob lives in which pack. Every uploaded pack gets its own small encrypted index file
 * (index/<packId>), written right after the pack, so an interrupted run leaves a consistent
 * repository and the next run can reuse what was already uploaded.
 *
 * Phase 0 keeps the whole index in memory. For large repositories this becomes a table in the
 * Nextcloud database (a cache that can always be rebuilt from index/ and the pack headers).
 */
final class BlobIndex {
	/** @var array<string, array{0:string, 1:int, 2:int, 3:int, 4:int}> id => [pack, offset, length, raw, flags] */
	private array $blobs = [];
	/** @var array<string, true> blobs added in this run but whose pack is not uploaded yet */
	private array $pending = [];

	private ?IIndexCache $cache = null;
	/** @var list<\Closure():void>|null cache writes held back by holdCache(), or null when writing directly */
	private ?array $heldCacheWrites = null;
	private string $repositoryId = '';
	/** Index files written to the cache per database transaction while loading. */
	private const CACHE_LOT = 256;
	/** @var int index files downloaded by the last load() (for tests and diagnostics) */
	public int $downloaded = 0;
	/** @var array<string, true> packs whose index file is loaded (refresh() fetches only others) */
	private array $loaded = [];

	public function __construct(
		private IBackend $backend,
		private StreamCipher $cipher,
	) {
	}

	public function setCache(IIndexCache $cache, string $repositoryId): void {
		$this->cache = $cache;
		$this->repositoryId = $repositoryId;
	}

	/**
	 * Hold back cache writes until releaseCache(). The database dump reads inside a read-only
	 * transaction on the same connection that the cache writes to: a write there fails on
	 * PostgreSQL and would be rolled back with the dump transaction elsewhere.
	 */
	public function holdCache(): void {
		$this->heldCacheWrites ??= [];
	}

	/** Write the cache changes held back since holdCache(). */
	public function releaseCache(): void {
		$writes = $this->heldCacheWrites ?? [];
		$this->heldCacheWrites = null;
		foreach ($writes as $write) {
			$write();
		}
	}

	/** @param list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries */
	private function cachePut(string $packId, array $entries): void {
		if ($this->heldCacheWrites !== null) {
			$this->heldCacheWrites[] = function () use ($packId, $entries): void {
				$this->cache?->put($this->repositoryId, $packId, $entries);
			};
			return;
		}
		$this->cache?->put($this->repositoryId, $packId, $entries);
	}

	/** @param list<string> $packIds */
	private function cacheRemove(array $packIds): void {
		if ($this->heldCacheWrites !== null) {
			$this->heldCacheWrites[] = function () use ($packIds): void {
				$this->cache?->remove($this->repositoryId, $packIds);
			};
			return;
		}
		$this->cache?->remove($this->repositoryId, $packIds);
	}

	/**
	 * Load the index: one listing of index/, entries from the local cache where available,
	 * and only the index files that are not cached yet are downloaded. Cached packs that no
	 * longer exist on the backend are dropped from the cache.
	 */
	public function load(bool $verify = false, ?callable $progress = null): void {
		$hadIndex = $this->blobs !== [];
		$cached = $verify ? [] : ($this->cache?->all($this->repositoryId) ?? []);
		$present = [];
		$this->downloaded = 0;
		$order = []; // pack ids in listing order: where a blob is in two packs, the last listed wins
		$fetch = [];
		$known = $hadIndex || $cached !== [] || ($verify && ($this->cache?->all($this->repositoryId) ?? []) !== []);
		foreach ($this->listIndex($known) as $path) {
			$packId = basename($path);
			$present[$packId] = true;
			$order[] = $packId;
			if (!isset($cached[$packId])) {
				$fetch[] = $path;
			}
		}
		// only now: an empty listing that listIndex() refused leaves the index as it was
		$this->blobs = [];
		$this->loaded = $present;
		// The rest is downloaded several at a time where the location allows it: on a large
		// repository these are tens of thousands of small files. getMany() keeps their order, so
		// the cached packs listed before each one are added first.
		$next = 0;
		$total = count($fetch);
		$toCache = []; // written to the cache a few hundred at a time, each lot in one transaction
		foreach ($this->backend->getMany($fetch) as $path => $data) {
			$packId = basename($path);
			$entries = json_decode($this->cipher->decryptString($data, 'index:' . $packId), true, 512, JSON_THROW_ON_ERROR);
			$this->downloaded++;
			if ($this->cache !== null) {
				$toCache[$packId] = $entries;
				// a check writes the whole cache afresh at the end
				if (!$verify && count($toCache) >= self::CACHE_LOT) {
					$this->cache->putMany($this->repositoryId, $toCache);
					$toCache = [];
				}
			}
			for (; $next < count($order) && $order[$next] !== $packId; $next++) {
				$this->add($order[$next], $cached[$order[$next]] ?? []);
			}
			$this->add($packId, $entries);
			$next++;
			if ($progress !== null) {
				$progress($this->downloaded, $total);
			}
		}
		for (; $next < count($order); $next++) {
			$this->add($order[$next], $cached[$order[$next]] ?? []);
		}
		if ($verify && $this->cache !== null) {
			// Refresh the cache from what was just verified, as the index files list it: a blob in
			// two packs is in both rows, so that removing one of the packs leaves the other right.
			// In one transaction: one row at a time took more than 6 minutes on a 1 TB repository
			// (27,547 packs), long enough for the location to drop the connection.
			$this->cache->replaceAll($this->repositoryId, $toCache);
			return;
		}
		if ($toCache !== []) {
			$this->cache?->putMany($this->repositoryId, $toCache);
		}
		$stale = array_keys(array_diff_key($cached, $present));
		if ($stale !== []) {
			$this->cache?->remove($this->repositoryId, $stale);
		}
	}

	/** @param list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries */
	private function add(string $packId, array $entries): void {
		foreach ($entries as $e) {
			$this->blobs[$e['id']] = [$packId, $e['offset'], $e['length'], $e['raw'], $e['flags']];
		}
	}

	public function has(string $id): bool {
		return isset($this->blobs[$id]) || isset($this->pending[$id]);
	}

	/** @return array{0:string, 1:int, 2:int, 3:int, 4:int} */
	public function get(string $id): array {
		return $this->blobs[$id] ?? throw new RepositoryException('Blob not in index: ' . $id);
	}

	public function addPending(string $id): void {
		$this->pending[$id] = true;
	}

	/** @param list<string> $ids */
	public function dropPending(array $ids): void {
		foreach ($ids as $id) {
			unset($this->pending[$id]);
		}
	}

	/** @param list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries */
	public function addPack(string $packId, array $entries): void {
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $this->cipher->encryptString(json_encode($entries, JSON_THROW_ON_ERROR), 'index:' . $packId));
		rewind($stream);
		$this->backend->put('index/' . $packId, $stream);
		$this->cachePut($packId, $entries);
		$this->loaded[$packId] = true;
		foreach ($entries as $e) {
			$this->blobs[$e['id']] = [$packId, $e['offset'], $e['length'], $e['raw'], $e['flags']];
			unset($this->pending[$e['id']]);
		}
	}

	/**
	 * Add the index files that appeared on the location since load(), such as those of packs
	 * another server's cleanup wrote while a restore runs. Only adds and updates: a blob is
	 * never dropped, as a listing that misses files (a connection that failed) must not make a
	 * restore lose blobs it knows. A blob in a new pack takes that pack's place.
	 */
	public function refresh(): void {
		$fetch = [];
		$listed = 0;
		foreach ($this->listIndex($this->loaded !== []) as $path) {
			$listed++;
			if (!isset($this->loaded[basename($path)])) {
				$fetch[] = $path;
			}
		}
		$toCache = [];
		foreach ($this->backend->getMany($fetch) as $path => $data) {
			$packId = basename($path);
			/** @var list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries authenticated: written by NG Backup */
			$entries = json_decode($this->cipher->decryptString($data, 'index:' . $packId), true, 512, JSON_THROW_ON_ERROR);
			$this->downloaded++;
			$this->add($packId, $entries);
			$this->loaded[$packId] = true;
			$toCache[$packId] = $entries;
		}
		if ($toCache !== []) {
			$this->cache?->putMany($this->repositoryId, $toCache);
		}
	}

	/**
	 * The index files on the location. An empty listing while index files are known ($known) is
	 * usually a connection that failed quietly; taken as is, it would empty the index and the
	 * cache, make a check report every blob missing, or make a cleanup wipe the cache. So the
	 * connection is checked (the repository's config must be there) and the folder listed once
	 * more: empty again on a working connection, the repository really is empty (another server
	 * removed its last snapshot and cleaned up).
	 *
	 * @return list<string>
	 * @throws BackendException when the location does not answer as it should
	 */
	private function listIndex(bool $known): array {
		$paths = $this->backend->listFiles('index');
		if ($paths !== [] || !$known) {
			return $paths;
		}
		if (!$this->backend->exists('config')) {
			throw new BackendException('The location listed no index files and has no repository config: not trusting that listing');
		}
		return $this->backend->listFiles('index');
	}

	/** @return array<string, list<array{id:string, offset:int, length:int}>> packId => blobs currently located in that pack */
	public function packs(): array {
		$packs = [];
		foreach ($this->blobs as $id => [$pack, $offset, $length]) {
			$packs[$pack][] = ['id' => $id, 'offset' => $offset, 'length' => $length];
		}
		return $packs;
	}

	/** Forget a pack: delete its index file and cache row. Blobs that now live elsewhere are kept. */
	public function removePack(string $packId): void {
		$this->backend->delete('index/' . $packId);
		$this->cacheRemove([$packId]);
		unset($this->loaded[$packId]);
		foreach ($this->blobs as $id => $loc) {
			if ($loc[0] === $packId) {
				unset($this->blobs[$id]);
			}
		}
	}

	/** Number of packs that hold blobs of the index. */
	public function packCount(): int {
		$packs = [];
		foreach ($this->blobs as [$pack]) {
			$packs[$pack] = true;
		}
		return count($packs);
	}

	/** Bytes of all blobs in the index (encrypted size, i.e. what is stored in packs). */
	public function storedBytes(): int {
		$total = 0;
		foreach ($this->blobs as [, , $length]) {
			$total += $length;
		}
		return $total;
	}

	public function count(): int {
		return count($this->blobs);
	}
}
