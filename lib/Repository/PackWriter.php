<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Crypto\StreamCipher;

/**
 * Collects encrypted blobs into a pack in memory and uploads it when full.
 *
 * Pack layout: blob* | encrypted header | uint32 big-endian header length
 * The header lists (id, offset, length, rawLength, flags) of every blob, so a pack can be
 * re-indexed from the pack alone. Each blob is encrypted separately with its id as associated
 * data, so single blobs can be fetched with a range read and cannot be swapped.
 *
 * Only one pack is held at a time (php://temp keeps it in memory up to the limit).
 */
final class PackWriter {
	/** @var resource */
	private $buffer;
	private int $size = 0;
	/** @var list<array{id:string, offset:int, length:int, raw:int, flags:int}> */
	private array $entries = [];

	public function __construct(
		private IBackend $backend,
		private StreamCipher $cipher,
		private BlobIndex $index,
		private int $targetSize = 32 * 1048576,
	) {
		$this->reset();
	}

	/** Add a blob that is not yet in the repository. Returns the number of bytes added to the pack. */
	public function add(string $id, string $encrypted, int $rawLength, int $flags): int {
		fwrite($this->buffer, $encrypted);
		$this->entries[] = ['id' => $id, 'offset' => $this->size, 'length' => strlen($encrypted), 'raw' => $rawLength, 'flags' => $flags];
		$this->size += strlen($encrypted);
		// Make the blob known immediately, so a later duplicate in the same run is not stored twice.
		$this->index->addPending($id);
		if ($this->size >= $this->targetSize) {
			$this->flush();
		}
		return strlen($encrypted);
	}

	/** Upload the current pack (if any) and record it in the index. */
	public function flush(): void {
		if ($this->entries === []) {
			return;
		}
		$packId = bin2hex(random_bytes(16));
		$header = $this->cipher->encryptString(json_encode($this->entries, JSON_THROW_ON_ERROR), 'pack-header:' . $packId);
		fwrite($this->buffer, $header . pack('N', strlen($header)));
		rewind($this->buffer);
		$path = 'packs/' . substr($packId, 0, 2) . '/' . $packId;
		$this->backend->put($path, $this->buffer);
		$this->index->addPack($packId, $this->entries);
		$this->reset();
	}

	public function pendingBytes(): int {
		return $this->size;
	}

	private function reset(): void {
		if (isset($this->buffer)) {
			fclose($this->buffer);
		}
		$this->buffer = fopen('php://temp/maxmemory:' . ($this->targetSize + 8 * 1048576), 'w+b');
		$this->size = 0;
		$this->entries = [];
	}
}
