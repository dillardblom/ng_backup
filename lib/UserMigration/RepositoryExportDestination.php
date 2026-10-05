<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\UserMigration;

use OCA\NgBackup\Repository\Repository;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\UserMigrationException;

/**
 * Export destination for user_migration that writes straight into the encrypted repository:
 * no zip, no copy in the user's folder. Every exported path becomes a blob stream; the list
 * of paths is stored as one encrypted manifest (users/<uid>/<exportId>) when close() is called.
 */
final class RepositoryExportDestination implements IExportDestination {
	/** @var array<string, array{t:string, b?:list<string>, s?:int}> path => entry */
	private array $entries = [];
	private array $versions = [];
	private array $stats = ['newBlobs' => 0, 'dupBlobs' => 0, 'uploaded' => 0];
	private string $exportId;

	public function __construct(
		private Repository $repo,
		private string $uid,
	) {
		$this->exportId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
	}

	public function addFileContents(string $path, string $content): void {
		$writer = $this->repo->blobWriter($this->stats);
		$writer->write($content);
		$this->entries[self::norm($path)] = ['t' => 'f', 'b' => $writer->finish(), 's' => strlen($content)];
	}

	public function addFileAsStream(string $path, $stream): void {
		$writer = $this->repo->blobWriter($this->stats);
		while (!feof($stream)) {
			$chunk = fread($stream, 1048576);
			if ($chunk === false) {
				throw new UserMigrationException('Read error while exporting ' . $path);
			}
			$writer->write($chunk);
		}
		$this->entries[self::norm($path)] = ['t' => 'f', 'b' => $writer->finish(), 's' => $writer->bytes()];
	}

	public function copyFolder(Folder $folder, string $destinationPath, ?callable $nodeFilter = null): void {
		$base = self::norm($destinationPath);
		$this->entries[$base] = ['t' => 'd'];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($nodeFilter !== null && !$nodeFilter($node)) {
				continue;
			}
			$target = $base . '/' . $node->getName();
			if ($node instanceof Folder) {
				$this->copyFolder($node, $target, $nodeFilter);
			} elseif ($node instanceof File) {
				$stream = $node->fopen('rb');
				if ($stream === false) {
					throw new \RuntimeException('Cannot read ' . $node->getPath());
				}
				try {
					$this->addFileAsStream($target, $stream);
				} finally {
					fclose($stream);
				}
			}
		}
	}

	public function setMigratorVersions(array $versions): void {
		$this->versions = $versions;
	}

	public function close(): void {
		$this->repo->flushPacks();
		$manifest = ['uid' => $this->uid, 'time' => gmdate('c'), 'versions' => $this->versions, 'entries' => $this->entries];
		$this->repo->putObject($this->manifestPath(), json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	public function manifestPath(): string {
		return 'users/' . $this->uid . '/' . $this->exportId;
	}

	public function stats(): array {
		return $this->stats;
	}

	private static function norm(string $path): string {
		return trim($path, '/');
	}
}
