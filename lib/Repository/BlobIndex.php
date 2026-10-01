<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

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
	private string $repositoryId = '';
	/** @var int index files downloaded by the last load() (for tests and diagnostics) */
	public int $downloaded = 0;

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
	 * Load the index: one listing of index/, entries from the local cache where available,
	 * and only the index files that are not cached yet are downloaded. Cached packs that no
	 * longer exist on the backend are dropped from the cache.
	 */
	public function load(): void {
		$cached = $this->cache?->all($this->repositoryId) ?? [];
		$present = [];
		$this->downloaded = 0;
		foreach ($this->backend->list('index') as $path) {
			$packId = basename($path);
			$present[$packId] = true;
			if (isset($cached[$packId])) {
				$entries = $cached[$packId];
			} else {
				$fh = $this->backend->get($path);
				$entries = json_decode($this->cipher->decryptString((string)stream_get_contents($fh), 'index:' . $packId), true, 512, JSON_THROW_ON_ERROR);
				fclose($fh);
				$this->downloaded++;
				$this->cache?->put($this->repositoryId, $packId, $entries);
			}
			foreach ($entries as $e) {
				$this->blobs[$e['id']] = [$packId, $e['offset'], $e['length'], $e['raw'], $e['flags']];
			}
		}
		$stale = array_keys(array_diff_key($cached, $present));
		if ($stale !== []) {
			$this->cache?->remove($this->repositoryId, $stale);
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
		$this->cache?->put($this->repositoryId, $packId, $entries);
		foreach ($entries as $e) {
			$this->blobs[$e['id']] = [$packId, $e['offset'], $e['length'], $e['raw'], $e['flags']];
			unset($this->pending[$e['id']]);
		}
	}

	public function count(): int {
		return count($this->blobs);
	}
}
