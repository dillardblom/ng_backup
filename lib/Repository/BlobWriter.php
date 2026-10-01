<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

/**
 * Write side of a blob stream: data written here is cut into blobs as it arrives and handed
 * to the repository right away. Nothing is buffered beyond one blob, so a database dump or any
 * other generated stream never needs a temporary file.
 */
final class BlobWriter {
	private string $buffer = '';
	/** @var list<string> */
	private array $blobs = [];
	private int $bytes = 0;

	/** @param \Closure(string): string $store stores one blob, returns its id */
	public function __construct(
		private \Closure $store,
		private int $blobSize = Repository::BLOB_SIZE,
	) {
	}

	public function write(string $data): void {
		$this->buffer .= $data;
		$this->bytes += strlen($data);
		while (strlen($this->buffer) >= $this->blobSize) {
			$this->blobs[] = ($this->store)(substr($this->buffer, 0, $this->blobSize));
			$this->buffer = substr($this->buffer, $this->blobSize);
		}
	}

	/** @return list<string> blob ids, in order */
	public function finish(): array {
		if ($this->buffer !== '') {
			$this->blobs[] = ($this->store)($this->buffer);
			$this->buffer = '';
		}
		return $this->blobs;
	}

	public function bytes(): int {
		return $this->bytes;
	}
}
