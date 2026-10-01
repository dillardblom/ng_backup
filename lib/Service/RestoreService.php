<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\Target;
use OCA\NgBackup\Repository\Repository;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Restoring from a snapshot.
 *
 * User files (data/<uid>/files/...) are written through the Nextcloud Files API, so the file
 * cache, versions and trash stay consistent: an overwritten file becomes a version and a
 * removed file goes to the trash, which makes a restore itself reversible. Every file is
 * written to a temporary name first and only renamed into place after all its blobs were
 * decrypted and verified.
 *
 * Modes for user files:
 *  - new-folder (default): into "Restored <date>/<same path>", nothing existing is touched
 *  - merge:   into the original place, existing files are overwritten (old content kept as a version)
 *  - replace: like merge, and files that are not in the snapshot are moved to the trash
 */
class RestoreService {
	public const MODE_NEW_FOLDER = 'new-folder';
	public const MODE_MERGE = 'merge';
	public const MODE_REPLACE = 'replace';

	public function __construct(
		private TargetService $targets,
		private IRootFolder $root,
	) {
	}

	/**
	 * Direct children (files and folders) of a path in a snapshot.
	 *
	 * @return list<array{name:string, type:string, size:int, mtime:int|null, files:int}>
	 */
	public function browse(Target $target, string $snapshotId, string $path = ''): array {
		$prefix = trim($path, '/');
		$prefix = $prefix === '' ? '' : $prefix . '/';
		$children = [];
		foreach ($this->targets->repository($target)->entries($snapshotId) as $e) {
			if ($prefix !== '' && !str_starts_with($e['p'], $prefix)) {
				continue;
			}
			$rest = substr($e['p'], strlen($prefix));
			[$name, $more] = array_pad(explode('/', $rest, 2), 2, null);
			$c = $children[$name] ?? ['name' => $name, 'type' => $more === null ? 'file' : 'dir', 'size' => 0, 'mtime' => null, 'files' => 0];
			$c['size'] += $e['s'];
			$c['files']++;
			if ($more === null) {
				$c['mtime'] = $e['m'];
			}
			$children[$name] = $c;
		}
		ksort($children, SORT_STRING);
		return array_values($children);
	}

	/**
	 * Restore user files from "data/<uid>/files[/<path>]" into that user's Nextcloud files.
	 *
	 * @return array{restored:int, trashed:int, target:string}
	 */
	public function restoreUserFiles(Target $target, string $snapshotId, string $logicalPath, string $mode = self::MODE_NEW_FOLDER, ?callable $progress = null): array {
		if (!preg_match('#^data/([^/]+)/files(?:/(.*))?$#', trim($logicalPath, '/'), $m)) {
			throw new \InvalidArgumentException('Expected a path like data/<user>/files/<folder or file>');
		}
		[$uid, $rel] = [$m[1], trim($m[2] ?? '', '/')];
		$userFolder = $this->root->getUserFolder($uid);
		$repo = $this->targets->repository($target);
		$source = 'data/' . $uid . '/files' . ($rel === '' ? '' : '/' . $rel);

		$entries = [];
		$single = false;
		foreach ($repo->entries($snapshotId) as $e) {
			if ($e['p'] === $source) {
				$single = true;
				$entries[''] = $e;
				break;
			}
			if (str_starts_with($e['p'], $source . '/')) {
				$entries[substr($e['p'], strlen($source) + 1)] = $e;
			}
		}
		if ($entries === []) {
			throw new NotFoundException("Nothing at $source in snapshot $snapshotId");
		}

		// Where to write. new-folder mirrors the path under "Restored <date>" in the user's root.
		$parentRel = $single ? (dirname($rel) === '.' ? '' : dirname($rel)) : $rel;
		if ($mode === self::MODE_NEW_FOLDER) {
			$parentRel = trim('Restored ' . date('Y-m-d H.i') . '/' . $parentRel, '/');
		} elseif ($mode !== self::MODE_MERGE && $mode !== self::MODE_REPLACE) {
			throw new \InvalidArgumentException("Unknown mode $mode");
		}
		$dest = $this->ensureFolder($userFolder, $parentRel);
		$names = $single ? [basename($rel) => $entries['']] : $entries;

		$restored = 0;
		foreach ($names as $relPath => $entry) {
			$this->writeFile($repo, $dest, (string)$relPath, $entry);
			$restored++;
			if ($progress !== null) {
				$progress($restored, count($names), (string)$relPath);
			}
		}

		$trashed = 0;
		if ($mode === self::MODE_REPLACE && !$single) {
			$trashed = $this->trashExtras($dest, '', array_flip(array_keys($names)));
		}
		return ['restored' => $restored, 'trashed' => $trashed, 'target' => $uid . ':' . $userFolder->getRelativePath($dest->getPath())];
	}

	/** Raw restore of any snapshot path to a local directory (administrators, disaster recovery). */
	public function restoreToDirectory(Target $target, string $snapshotId, string $prefix, string $directory): int {
		if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
			throw new \RuntimeException("Cannot create $directory");
		}
		return $this->targets->repository($target)->restore($snapshotId, $directory, trim($prefix, '/') === '' ? '' : trim($prefix, '/') . '/');
	}

	private function writeFile(Repository $repo, Folder $dest, string $relPath, array $entry): void {
		$dir = dirname($relPath);
		$folder = $dir === '.' ? $dest : $this->ensureFolder($dest, $dir);
		$name = basename($relPath);
		// Stage under a temporary name; only rename into place after every blob was verified.
		$tmpName = '.' . $name . '.ngb-restore-' . bin2hex(random_bytes(3));
		$tmp = $folder->newFile($tmpName);
		try {
			$out = $tmp->fopen('wb');
			$written = 0;
			foreach ($repo->readBlobs($entry['b']) as $data) { // each blob is verified on read
				if (fwrite($out, $data) !== strlen($data)) {
					throw new \RuntimeException('Write failed for ' . $relPath);
				}
				$written += strlen($data);
			}
			if (!fclose($out)) {
				throw new \RuntimeException('Write failed for ' . $relPath);
			}
			if ($written !== $entry['s']) {
				throw new \RuntimeException("Size mismatch restoring $relPath");
			}
			if ($folder->nodeExists($name)) {
				$existing = $folder->get($name);
				if (!$existing instanceof File) {
					throw new \RuntimeException("$relPath exists and is not a file");
				}
				// Overwrite through the Files API: the current content becomes a version.
				$in = $tmp->fopen('rb');
				$existing->putContent($in);
				if (is_resource($in)) {
					fclose($in);
				}
				$tmp->delete();
				$existing->touch($entry['m']);
			} else {
				$tmp->move($folder->getPath() . '/' . $name);
				$folder->get($name)->touch($entry['m']);
			}
		} catch (\Throwable $e) {
			try {
				$tmp->delete();
			} catch (\Throwable) {
			}
			throw $e;
		}
	}

	private function trashExtras(Folder $folder, string $prefix, array $keep): int {
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			$rel = $prefix === '' ? $node->getName() : $prefix . '/' . $node->getName();
			if ($node instanceof Folder) {
				$hasKept = false;
				foreach (array_keys($keep) as $k) {
					if (str_starts_with((string)$k, $rel . '/')) {
						$hasKept = true;
						break;
					}
				}
				if ($hasKept) {
					$count += $this->trashExtras($node, $rel, $keep);
				} else {
					$node->delete(); // to the trash
					$count++;
				}
			} elseif (!isset($keep[$rel])) {
				$node->delete();
				$count++;
			}
		}
		return $count;
	}

	private function ensureFolder(Folder $base, string $path): Folder {
		$path = trim($path, '/');
		if ($path === '' || $path === '.') {
			return $base;
		}
		if ($base->nodeExists($path)) {
			$node = $base->get($path);
			if (!$node instanceof Folder) {
				throw new \RuntimeException("$path exists and is not a folder");
			}
			return $node;
		}
		return $base->newFolder($path);
	}
}
