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

	public function __construct(
		private IBackend $backend,
		private StreamCipher $cipher,
	) {
	}

	public function load(): void {
		foreach ($this->backend->list('index') as $path) {
			$packId = basename($path);
			$fh = $this->backend->get($path);
			$entries = json_decode($this->cipher->decryptString((string)stream_get_contents($fh), 'index:' . $packId), true, 512, JSON_THROW_ON_ERROR);
			fclose($fh);
			foreach ($entries as $e) {
				$this->blobs[$e['id']] = [$packId, $e['offset'], $e['length'], $e['raw'], $e['flags']];
			}
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

	/** @param list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries */
	public function addPack(string $packId, array $entries): void {
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $this->cipher->encryptString(json_encode($entries, JSON_THROW_ON_ERROR), 'index:' . $packId));
		rewind($stream);
		$this->backend->put('index/' . $packId, $stream);
		fclose($stream);
		foreach ($entries as $e) {
			$this->blobs[$e['id']] = [$packId, $e['offset'], $e['length'], $e['raw'], $e['flags']];
			unset($this->pending[$e['id']]);
		}
	}

	public function count(): int {
		return count($this->blobs);
	}
}
