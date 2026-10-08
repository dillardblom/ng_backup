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
	/**
	 * Store an object; the stream is read to EOF. Takes ownership of the stream: it is always
	 * closed afterwards (some storages, like files_external's S3, close it themselves).
	 * Must not leave a partial object behind on failure.
	 */
	public function put(string $path, $stream): void;

	/** @return resource readable stream of the whole object */
	public function get(string $path);

	/** @return string bytes [$offset, $offset + $length) of the object (used to fetch single blobs from a pack) */
	public function getRange(string $path, int $offset, int $length): string;

	public function exists(string $path): bool;

	/**
	 * Move an object within the same location (used for the trash). Backends must use a native
	 * rename, not a read+write copy: some storages (e.g. SFTP) cannot reliably read and write on
	 * the same connection at once, since the read side is a lazily-pulled stream.
	 */
	public function move(string $from, string $to): void;

	/** @return list<string> object paths below $prefix (recursive) */
	public function list(string $prefix): array;

	/**
	 * Object paths directly in $dir, for a folder that only holds objects (index/, snapshots/,
	 * catalog/, trash/info/). One listing, without checking the type of every entry: on a remote
	 * storage list() costs a round trip per entry, which for index/ (one file per pack) grows
	 * with the size of the repository.
	 *
	 * @return list<string>
	 */
	public function listFiles(string $dir): array;

	public function delete(string $path): void;
}
