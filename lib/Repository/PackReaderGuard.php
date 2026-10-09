<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

use OCA\NgBackup\Backend\IBackend;

/** The location as Repository sees it: closes the PackReader's open stream before every other request. */
final class PackReaderGuard implements IBackend {
	public function __construct(
		private IBackend $inner,
		private PackReader $reader,
	) {
	}

	/** @param resource $stream */
	public function put(string $path, $stream): void {
		$this->reader->close();
		$this->inner->put($path, $stream);
	}

	public function get(string $path) {
		$this->reader->close();
		return $this->inner->get($path);
	}

	public function getRange(string $path, int $offset, int $length): string {
		$this->reader->close();
		return $this->inner->getRange($path, $offset, $length);
	}

	public function exists(string $path): bool {
		$this->reader->close();
		return $this->inner->exists($path);
	}

	public function move(string $from, string $to): void {
		$this->reader->close();
		$this->inner->move($from, $to);
	}

	public function list(string $prefix): array {
		$this->reader->close();
		return $this->inner->list($prefix);
	}

	public function listFiles(string $dir): array {
		$this->reader->close();
		return $this->inner->listFiles($dir);
	}

	public function delete(string $path): void {
		$this->reader->close();
		$this->inner->delete($path);
	}
}
