<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\UserMigration;

use OCA\NgBackup\Repository\Repository;
use OCP\Files\Folder;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;

/** Import source for user_migration that reads a user export from the repository. */
final class RepositoryImportSource implements IImportSource {
	private array $manifest;

	public function __construct(
		private Repository $repo,
		string $manifestPath,
	) {
		$this->manifest = json_decode($repo->getObject($manifestPath), true, 512, JSON_THROW_ON_ERROR);
	}

	public function getFileContents(string $path): string {
		$stream = $this->getFileAsStream($path);
		$data = (string)stream_get_contents($stream);
		fclose($stream);
		return $data;
	}

	public function getFileAsStream(string $path) {
		$entry = $this->entry($path, 'f');
		// Phase 0: buffer in php://temp (memory up to 8 MiB, then a temp file for this one file).
		$stream = fopen('php://temp/maxmemory:' . (8 * 1048576), 'w+b');
		foreach ($this->repo->readBlobs($entry['b']) as $data) {
			for ($done = 0, $len = strlen($data); $done < $len; $done += $n) {
				$n = fwrite($stream, $done === 0 ? $data : substr($data, $done));
				if ($n === false || $n === 0) {
					fclose($stream);
					throw new UserMigrationException('Cannot buffer ' . $path);
				}
			}
		}
		rewind($stream);
		return $stream;
	}

	public function getFolderListing(string $path): array {
		$prefix = trim($path, '/');
		$prefix = $prefix === '' ? '' : $prefix . '/';
		$names = [];
		foreach (array_keys($this->manifest['entries']) as $p) {
			if ($prefix === '' || str_starts_with($p, $prefix)) {
				$rest = substr($p, strlen($prefix));
				if ($rest !== '') {
					// First path segment: also lists folders that only exist as a parent of a file.
					$names[explode('/', $rest, 2)[0]] = true;
				}
			}
		}
		$names = array_keys($names);
		sort($names);
		return $names;
	}

	public function pathExists(string $path): bool {
		$p = trim($path, '/');
		if (isset($this->manifest['entries'][$p])) {
			return true;
		}
		foreach (array_keys($this->manifest['entries']) as $k) {
			if (str_starts_with($k, $p . '/')) {
				return true;
			}
		}
		return false;
	}

	public function copyToFolder(Folder $destination, string $sourcePath): void {
		foreach ($this->getFolderListing($sourcePath) as $name) {
			$base = trim($sourcePath, '/');
			$path = $base === '' ? $name : $base . '/' . $name;
			$entry = $this->manifest['entries'][$path] ?? ['t' => 'd'];
			if ($entry['t'] === 'd') {
				$sub = $destination->nodeExists($name) ? $destination->get($name) : $destination->newFolder($name);
				if (!$sub instanceof Folder) {
					throw new UserMigrationException("$path exists and is not a folder");
				}
				$this->copyToFolder($sub, $path);
			} else {
				$stream = $this->getFileAsStream($path);
				if ($destination->nodeExists($name)) {
					$destination->get($name)->putContent($stream);
				} else {
					$destination->newFile($name, $stream);
				}
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
		}
	}

	public function getMigratorVersions(): array {
		return $this->manifest['versions'];
	}

	public function getMigratorVersion(string $migrator): ?int {
		return $this->manifest['versions'][$migrator] ?? null;
	}

	public function getOriginalUid(): string {
		return $this->manifest['uid'];
	}

	public function close(): void {
	}

	/** @return array{t:string, b:list<string>, s:int} */
	private function entry(string $path, string $type): array {
		$entry = $this->manifest['entries'][trim($path, '/')] ?? null;
		if ($entry === null || $entry['t'] !== $type) {
			throw new UserMigrationException('Path not found in backup: ' . $path);
		}
		return $entry;
	}
}
