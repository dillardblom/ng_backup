<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/**
 * Minimal object store used by the repository. Paths are relative, '/'-separated.
 * Implementations: a local directory (spike, tests), and adapters over files_external storages.
 */
interface IBackend {
	/** Store an object; the stream is read to EOF. Must not leave a partial object behind on failure. */
	public function put(string $path, $stream): void;

	/** @return resource readable stream of the whole object */
	public function get(string $path);

	/** @return string bytes [$offset, $offset + $length) of the object (used to fetch single blobs from a pack) */
	public function getRange(string $path, int $offset, int $length): string;

	public function exists(string $path): bool;

	/** @return list<string> object paths below $prefix (recursive) */
	public function list(string $prefix): array;

	public function delete(string $path): void;
}
