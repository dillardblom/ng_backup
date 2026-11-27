<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\DbDumper;
use OCA\NgBackup\Repository\Repository;

/**
 * Files that Nextcloud's server-side encryption had encrypted at the backup (#33).
 *
 * The backup holds the data directory as it is on disk: such a file as Nextcloud encrypted it,
 * its keys under files_encryption/keys/files/<path>/, and in the database dump the "encryption
 * version" Nextcloud needs to read it (the column filecache.encrypted, which counts the times the
 * file was written) and its size before encryption. Writing those bytes back through the Files
 * API, as for any other file, encrypts them a second time: the file then downloads as an
 * "HBEGIN:oc_encryption_module" file.
 *
 * RestoreService therefore puts the encrypted file and its keys back as they were, under a
 * temporary name, gives Nextcloud the version and size from the dump, and reads the file back
 * through the Files API: Nextcloud itself decrypts it, with the instance's own keys and its own
 * code, and the content is then written as a normal new file (encrypted again by Nextcloud, with
 * a new key). NG Backup holds no crypto of its own for this.
 */
final class EncryptedFiles {
	/** What every file encrypted by Nextcloud starts with. */
	public const HEADER = 'HBEGIN:';

	/**
	 * The encryption version and size of the encrypted files below $source in the dump of the
	 * snapshot.
	 *
	 * @param string $source data/<uid>/files[/...]
	 * @return array<string, array{version: int, size: int}> per path relative to $source ('' for $source itself)
	 * @throws \RuntimeException when the snapshot has no database dump, or no row for this user's files
	 */
	public static function fromDump(Repository $repo, string $snapshotId, string $uid, string $source): array {
		$meta = $repo->snapshotMeta($snapshotId);
		if (!isset($meta['db']) || !is_string($meta['db'])) {
			throw new \RuntimeException('This restore point has no database dump, which encrypted files need to be read back');
		}
		$manifest = json_decode($repo->getObject($meta['db']), true, 512, JSON_THROW_ON_ERROR);
		$prefix = DbDumper::dumpPrefix($manifest) ?? 'oc_';
		$storages = $manifest['tables'][$prefix . 'storages']['blobs'] ?? null;
		$filecache = $manifest['tables'][$prefix . 'filecache']['blobs'] ?? null;
		if (!is_array($storages) || !is_array($filecache)) {
			throw new \RuntimeException('The database dump of this restore point has no file cache, which encrypted files need to be read back');
		}

		// The user's home storage; Nextcloud hashes storage ids longer than 64 characters.
		$homeId = 'home::' . $uid;
		if (strlen($homeId) > 64) {
			$homeId = md5($homeId);
		}
		$storage = null;
		foreach (self::rows($repo, $storages) as $row) {
			if ((string)($row['id'] ?? '') === $homeId) {
				$storage = (int)$row['numeric_id'];
				break;
			}
		}
		if ($storage === null) {
			throw new \RuntimeException("The database dump of this restore point has no files of user $uid");
		}

		$base = substr($source, strlen('data/' . $uid . '/')); // files[/...]
		$out = [];
		foreach (self::rows($repo, $filecache) as $row) {
			if ((int)($row['storage'] ?? -1) !== $storage || (int)($row['encrypted'] ?? 0) <= 0) {
				continue;
			}
			$path = (string)($row['path'] ?? '');
			if ($path !== $base && !str_starts_with($path, $base . '/')) {
				continue;
			}
			$plain = (int)($row['unencrypted_size'] ?? 0);
			$out[$path === $base ? '' : substr($path, strlen($base) + 1)] = [
				'version' => (int)$row['encrypted'],
				'size' => $plain > 0 ? $plain : (int)($row['size'] ?? 0),
			];
		}
		return $out;
	}

	/**
	 * The rows of a dumped table as column => value.
	 *
	 * @param list<string> $blobs
	 * @return \Generator<array<string, mixed>>
	 */
	private static function rows(Repository $repo, array $blobs): \Generator {
		$columns = null;
		foreach ($repo->readLines($blobs) as $line) {
			if ($line === '') {
				continue;
			}
			$value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
			if ($columns === null) {
				$columns = $value['columns'] ?? null;
				if (!is_array($columns)) {
					throw new \RuntimeException('A table in the database dump cannot be read');
				}
				continue;
			}
			if (is_array($value) && count($value) === count($columns)) {
				yield array_combine($columns, $value);
			}
		}
	}

	/**
	 * The key files of each file below a path, from the snapshot entries under
	 * data/<uid>/files_encryption/keys/files/<path>: per file, "<module>/<key file>" => entry.
	 *
	 * @param array<string, array> $keyEntries entries relative to the keys folder of $source
	 * @return array<string, array> for one file (path relative to $source, '' for $source itself)
	 */
	public static function keysOf(array $keyEntries, string $relPath): array {
		$prefix = $relPath === '' ? '' : $relPath . '/';
		$out = [];
		foreach ($keyEntries as $path => $entry) {
			$path = (string)$path;
			if ($prefix !== '' && !str_starts_with($path, $prefix)) {
				continue;
			}
			$rest = substr($path, strlen($prefix));
			if (preg_match('#^[^/]+/[^/]+$#', $rest)) { // <module>/<key file>, nothing deeper
				$out[$rest] = $entry;
			}
		}
		return $out;
	}
}
