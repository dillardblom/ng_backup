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
final class RestoreService {
	public const MODE_NEW_FOLDER = 'new-folder';
	public const MODE_MERGE = 'merge';
	public const MODE_REPLACE = 'replace';

	/** @var array<string, true> folders of the current restore already cleared of stale temporaries */
	private array $cleaned = [];

	public function __construct(
		private TargetService $targets,
		private IRootFolder $root,
		private LeaseService $leases,
	) {
	}

	/** Shared lock on the location while restoring: a cleanup (exclusive) must not delete what is being read. */
	private function withSharedLock(Target $target, callable $fn, int $ttl = 600): mixed {
		try {
			return $this->leases->with(PruneService::lockKey($target), LeaseService::SHARED, $ttl, $fn);
		} catch (\OCP\Lock\LockedException) {
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is running; try again later');
		}
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
		$repo = $this->targets->repository($target);
		foreach ($repo->entries($snapshotId) as $e) {
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
		foreach ($repo->emptyDirs($snapshotId) as [$dir]) { // read along with the entries
			if ($prefix === '' || str_starts_with($dir, $prefix)) {
				$name = explode('/', substr($dir, strlen($prefix)), 2)[0];
				$children[$name] ??= ['name' => $name, 'type' => 'dir', 'size' => 0, 'mtime' => null, 'files' => 0];
			}
		}
		ksort($children, SORT_STRING);
		return array_values($children);
	}

	/**
	 * Restore user files from "data/<uid>/files[/<path>]" into that user's Nextcloud files.
	 *
	 * @return array{restored:int, trashed:int, target:string}
	 */
	public function restoreUserFiles(Target $target, string $snapshotId, string $logicalPath, string $mode = self::MODE_NEW_FOLDER, ?callable $progress = null, ?array &$skipped = null): array {
		return $this->withSharedLock($target, function (callable $refresh) use ($target, $snapshotId, $logicalPath, $mode, $progress, &$skipped) {
			$last = time();
			// Keep the lease alive while files are being written.
			$beat = function (int $done, int $total, string $name) use ($refresh, $progress, &$last): void {
				if (time() - $last >= 60) {
					$refresh();
					$last = time();
				}
				if ($progress !== null) {
					$progress($done, $total, $name);
				}
			};
			return $this->doRestoreUserFiles($target, $snapshotId, $logicalPath, $mode, $beat, $skipped);
		});
	}

	/** @param array<string, string>|null $skipped see Repository::restore() */
	private function doRestoreUserFiles(Target $target, string $snapshotId, string $logicalPath, string $mode, ?callable $progress, ?array &$skipped): array {
		if (!Repository::isSafePath($logicalPath) || !preg_match('#^data/([^/]+)/files(?:/(.*))?$#', $logicalPath, $m)) {
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
		// Folders without anything in them, relative to $source ('' when $source itself is one).
		/** @var array<array-key, int> $emptyDirs modification times; a folder called "2026" is an integer key */
		$emptyDirs = [];
		foreach ($single ? [] : $repo->emptyDirs($snapshotId) as [$dir, $mtime]) {
			if ($dir === $source) {
				$emptyDirs[''] = $mtime;
			} elseif (str_starts_with($dir, $source . '/')) {
				$emptyDirs[substr($dir, strlen($source) + 1)] = $mtime;
			}
		}
		if ($entries === [] && $emptyDirs === []) {
			throw new NotFoundException("Nothing at $source in snapshot $snapshotId");
		}

		// Where to write. new-folder mirrors the path under "Restored <date>" in the user's root.
		$parentRel = $single ? (dirname($rel) === '.' ? '' : dirname($rel)) : $rel;
		if ($mode === self::MODE_NEW_FOLDER) {
			$parentRel = trim('Restored ' . date('Y-m-d H.i') . '/' . $parentRel, '/');
		} elseif ($mode !== self::MODE_MERGE && $mode !== self::MODE_REPLACE) {
			throw new \InvalidArgumentException("Unknown mode $mode");
		}
		$destExisted = $parentRel === '' || $userFolder->nodeExists($parentRel);
		$dest = $this->ensureFolder($userFolder, $parentRel);
		$names = $single ? [basename($rel) => $entries['']] : $entries;

		// The folders with something in them that this restore creates get their time back; one that
		// was already there keeps its own, as its content changes now. Noted before anything is written.
		/** @var array<array-key, int> $newFolders a folder called "2026" is an integer key */
		$newFolders = [];
		foreach ($single ? [] : $repo->folderTimes($snapshotId) as [$dir, $mtime]) {
			$relative = $dir === $source ? '' : (str_starts_with($dir, $source . '/') ? substr($dir, strlen($source) + 1) : null);
			if ($relative !== null && ($relative === '' ? !$destExisted : !$dest->nodeExists($relative))) {
				$newFolders[$relative] = $mtime;
			}
		}

		$this->cleaned = [];
		$restored = 0;
		foreach ($names as $relPath => $entry) {
			try {
				$this->writeFile($repo, $dest, (string)$relPath, $entry);
			} catch (\OCA\NgBackup\Repository\RepositoryException|\OCA\NgBackup\Backend\BackendException|\OCA\NgBackup\Crypto\CryptoException $e) {
				// Damaged or missing on the location (writeFile removed its temporary)
				if ($skipped === null) {
					throw $e;
				}
				$skipped[$entry['p']] = $e->getMessage();
				continue;
			}
			$restored++;
			if ($progress !== null) {
				$progress($restored, count($names), (string)$relPath);
			}
		}

		foreach ($emptyDirs as $dir => $mtime) {
			$dir = (string)$dir;
			$existed = $dir === '' ? $destExisted : $dest->nodeExists($dir); // one that is there keeps its own time
			$folder = $this->ensureFolder($dest, $dir);
			if (!$existed) {
				$folder->touch($mtime);
			}
			if ($progress !== null) {
				$progress($restored, count($names), $dir); // keeps the lease alive too
			}
		}

		$trashed = 0;
		if ($mode === self::MODE_REPLACE && !$single) {
			$trashed = $this->trashExtras($dest, '', array_flip(array_keys($names)) + $emptyDirs);
		}
		// Last and deepest first: a change in a folder also touches the folders above it.
		foreach (array_reverse($newFolders, true) as $dir => $mtime) {
			$this->ensureFolder($dest, (string)$dir)->touch($mtime);
			if ($progress !== null) {
				$progress($restored, count($names), (string)$dir); // keeps the lease alive too
			}
		}
		return ['restored' => $restored, 'trashed' => $trashed, 'target' => $uid . ':' . $userFolder->getRelativePath($dest->getPath())];
	}

	/**
	 * Raw restore of any snapshot path to a local directory (administrators, disaster recovery).
	 *
	 * @param bool $stripPrefix write into $directory itself instead of $directory/$prefix (see
	 *             Repository::restore()); used to restore "data/" or "config/" straight into the
	 *             real data/config directory for occ backup:restore:full.
	 * @param bool $keepUnchanged leave files already in place with the snapshot's size and
	 *             modification time (see Repository::restore()): a full restore that runs again
	 *             goes on where it stopped
	 */
	public function restoreToDirectory(Target $target, string $snapshotId, string $prefix, string $directory, bool $stripPrefix = false, ?callable $heartbeat = null, ?array &$skipped = null, bool $keepUnchanged = false): int {
		if ($prefix !== '' && !Repository::isSafePath($prefix)) {
			throw new \InvalidArgumentException('Invalid path: ' . $prefix);
		}
		if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
			throw new \OCA\NgBackup\Repository\RestoreTargetException("Cannot create $directory (disk full, or no permission?)");
		}
		return $this->withSharedLock($target, function (callable $refresh) use ($target, $snapshotId, $prefix, $directory, $stripPrefix, $heartbeat, &$skipped, $keepUnchanged): int {
			$own = LeaseService::throttled($refresh);
			$beat = static function () use ($own, $heartbeat): void {
				$own();
				if ($heartbeat !== null) {
					$heartbeat();
				}
			};
			return $this->targets->repository($target)->restore($snapshotId, $directory, trim($prefix, '/') === '' ? '' : trim($prefix, '/') . '/', $stripPrefix, $beat, $skipped, $keepUnchanged);
		}, 3600);
	}

	private function writeFile(Repository $repo, Folder $dest, string $relPath, array $entry): void {
		$dir = dirname($relPath);
		$folder = $dir === '.' ? $dest : $this->ensureFolder($dest, $dir);
		if (!isset($this->cleaned[$folder->getPath()])) {
			$this->removeStaleTemporaries($folder);
			$this->cleaned[$folder->getPath()] = true;
		}
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
				throw new \OCA\NgBackup\Repository\RepositoryException("Size mismatch restoring $relPath");
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

	/** See Repository::removeStaleTemporaries(): what a restore that was stopped left in this folder. */
	private function removeStaleTemporaries(Folder $folder): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && Repository::isRestoreTemporary($node->getName())) {
				try {
					$node->delete();
				} catch (\Throwable) {
					// in the way of nothing: the new temporary gets a name of its own
				}
			}
		}
	}

	private function trashExtras(Folder $folder, string $prefix, array $keep): int {
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			$rel = $prefix === '' ? $node->getName() : $prefix . '/' . $node->getName();
			if ($node instanceof Folder) {
				$hasKept = isset($keep[$rel]); // a folder that was empty in the snapshot
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
