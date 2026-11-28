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
	/** The current restore may meet files encrypted by server-side encryption (#33). */
	private bool $sseContext = false;

	public function __construct(
		private TargetService $targets,
		private IRootFolder $root,
		private LeaseService $leases,
		private \OCP\IUserManager $users,
		private \OCP\Encryption\IManager $encryption,
		private \OCP\Files\IMimeTypeDetector $mimeTypes,
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
		// Server-side encryption (#33): the keys of the files, under files_encryption/keys/files/<same path>/.
		$keysSource = 'data/' . $uid . '/files_encryption/keys/files' . ($rel === '' ? '' : '/' . $rel);
		$keyEntries = [];
		$userPrefix = 'data/' . $uid . '/';
		$inUser = false;
		foreach ($repo->entries($snapshotId) as $e) {
			// Entries come depth first, so everything of this user is together: past it, stop.
			if (str_starts_with($e['p'], $userPrefix)) {
				$inUser = true;
			} elseif ($inUser) {
				break;
			}
			if ($e['p'] === $source) {
				$single = true;
				$entries[''] = $e;
			} elseif (str_starts_with($e['p'], $source . '/')) {
				$entries[substr($e['p'], strlen($source) + 1)] = $e;
			} elseif (str_starts_with($e['p'], $keysSource . '/')) {
				$keyEntries[substr($e['p'], strlen($keysSource) + 1)] = $e;
			}
		}
		if ($single) {
			$entries = ['' => $entries['']];
		}
		$encrypted = [];
		// With encryption on here, or keys in the restore point, a file can be encrypted even when
		// its keys are missing (unreadable at the backup): writeFile() then refuses its bytes.
		$this->sseContext = $keyEntries !== [] || $this->encryption->isEnabled();
		if ($this->sseContext) {
			try {
				$encrypted = EncryptedFiles::fromDump($repo, $snapshotId, $uid, $source);
			} catch (\RuntimeException $e) {
				if ($keyEntries !== []) {
					throw $e;
				}
				// no usable dump and no keys: plain files restore as usual, encrypted bytes are refused per file
			}
			if ($encrypted !== [] && !$this->encryption->isEnabled()) {
				throw new \RuntimeException('These files were encrypted by Nextcloud\'s server-side encryption when they were backed up, and it is off now: turn it on again (occ encryption:enable) to restore them, or restore them raw with occ backup:restore -d');
			}
			if ($encrypted !== [] && !self::usesMasterKey()) {
				// Per-user keys only open in that user's own session: not for an admin, occ or cron.
				throw new \RuntimeException('These files were encrypted with per-user keys (server-side encryption without a master key), which only open in the user\'s own session. NG Backup can restore them only with master key encryption (the default); restore them raw with occ backup:restore -d, or restore the whole server');
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
		/** @var array<array-key, array{p:string, s:int, m:int, b:list<string>}> $names a file called "2026" is an integer key */
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
			// for a single file its entry is under '' in $encrypted and $keyEntries
			$sourceRel = $single ? '' : (string)$relPath;
			try {
				if (isset($encrypted[$sourceRel])) {
					$this->writeEncryptedFile($repo, $uid, $userFolder, $dest, (string)$relPath, $entry,
						$encrypted[$sourceRel], EncryptedFiles::keysOf($keyEntries, $sourceRel));
				} else {
					$this->writeFile($repo, $dest, (string)$relPath, $entry);
				}
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

	private function folderFor(Folder $dest, string $relPath): Folder {
		$dir = dirname($relPath);
		$folder = $dir === '.' ? $dest : $this->ensureFolder($dest, $dir);
		if (!isset($this->cleaned[$folder->getPath()])) {
			$this->removeStaleTemporaries($folder);
			$this->cleaned[$folder->getPath()] = true;
		}
		return $folder;
	}

	private function writeFile(Repository $repo, Folder $dest, string $relPath, array $entry): void {
		// each blob is verified on read
		$chunks = $repo->readBlobs($entry['b']);
		$this->writeContent($this->folderFor($dest, $relPath), $relPath,
			$this->sseContext ? self::refuseEncrypted($chunks, $relPath) : $chunks, (int)$entry['s'], (int)$entry['m']);
	}

	/**
	 * The content as it is, unless it starts as a file encrypted by server-side encryption: such
	 * bytes without their keys or version would be encrypted a second time (#33).
	 *
	 * @param iterable<string> $chunks
	 * @return \Generator<string>
	 */
	private static function refuseEncrypted(iterable $chunks, string $relPath): \Generator {
		$first = true;
		foreach ($chunks as $data) {
			if ($first && str_starts_with($data, EncryptedFiles::HEADER)) {
				$head = substr($data, 0, 8192);
				if (str_contains($head, 'oc_encryption_module') && str_contains($head, 'HEND')) {
					throw new \OCA\NgBackup\Repository\RepositoryException("$relPath was encrypted by server-side encryption, but its keys or encryption version are not in this restore point; restore it raw with occ backup:restore -d");
				}
			}
			$first = false;
			yield $data;
		}
	}

	/**
	 * A file that server-side encryption had encrypted (#33): its encrypted bytes and keys go back
	 * as they were, under a temporary name in the same folder, with the encryption version and
	 * size from the database dump. Reading it through the Files API then gives the content, which
	 * is written like any other file. The temporary is removed from disk and the file cache
	 * directly, so it does not end up in the user's trash.
	 *
	 * @param array{version: int, size: int} $info
	 * @param array<string, array> $keys "<module>/<key file>" => snapshot entry
	 */
	private function writeEncryptedFile(Repository $repo, string $uid, Folder $userFolder, Folder $dest, string $relPath, array $entry, array $info, array $keys): void {
		$folder = $this->folderFor($dest, $relPath);
		if ($keys === []) {
			throw new \OCA\NgBackup\Repository\RepositoryException("The encryption keys of $relPath are not in this restore point");
		}
		$user = $this->users->get($uid);
		if ($user === null) {
			throw new \RuntimeException("User $uid does not exist");
		}
		// The bytes go straight to disk, past Nextcloud's encryption: only the user's own home
		// storage on the local disk can take them that way (not a share or another mount).
		// (isLocal() says false here: the trash bin and encryption layers wrap the local storage.)
		$storage = $folder->getStorage();
		$homeId = 'home::' . $uid;
		$home = rtrim($user->getHome(), '/');
		$inUser = trim($userFolder->getRelativePath($folder->getPath()) ?? '', '/');
		if ($folder->getMountPoint()->getMountPoint() !== '/' . $uid . '/'
			|| $storage->getId() !== (strlen($homeId) > 64 ? md5($homeId) : $homeId)
			|| $folder->getInternalPath() !== trim('files/' . $inUser, '/')
			|| !is_dir($home . '/files/' . $inUser)) {
			throw new \RuntimeException("$relPath was encrypted by server-side encryption; it can only be restored into the user's own files on this server's disk, not into a share or another storage");
		}
		$stageName = '.' . basename($relPath) . '.ngb-restore-' . bin2hex(random_bytes(3));
		$stageRel = ($inUser === '' ? '' : $inUser . '/') . $stageName;
		$stageFile = $home . '/files/' . $stageRel;
		$stageKeys = $home . '/files_encryption/keys/files/' . $stageRel;
		try {
			if (!is_dir(dirname($stageFile))) {
				throw new \RuntimeException("The folder of $relPath is not on the local disk where it was expected");
			}
			$this->writeRaw($stageFile, $repo->readBlobs($entry['b']), (int)$entry['s'], $relPath);
			foreach ($keys as $keyPath => $keyEntry) {
				$this->writeRaw($stageKeys . '/' . $keyPath, $repo->readBlobs($keyEntry['b']), (int)$keyEntry['s'], $relPath . ' (key)');
			}
			$head = (string)file_get_contents($stageFile, false, null, 0, strlen(EncryptedFiles::HEADER));
			$isEncrypted = $head === EncryptedFiles::HEADER;
			// Into the file cache directly, with the encryption version and size from the dump: a scan
			// would go through the encryption layer, which then remembers the file as not encrypted.
			$internal = trim($folder->getInternalPath() . '/' . $stageName, '/');
			$cache = $storage->getCache();
			$cache->put($internal, [
				'mtime' => time(), 'storage_mtime' => (int)filemtime($stageFile),
				'size' => $isEncrypted ? $info['size'] : (int)$entry['s'],
				'mimetype' => $this->mimeTypes->detectPath($stageName),
				'etag' => bin2hex(random_bytes(8)),
				'permissions' => \OCP\Constants::PERMISSION_ALL & ~\OCP\Constants::PERMISSION_CREATE,
				'encrypted' => $isEncrypted, 'encryptedVersion' => $isEncrypted ? $info['version'] : 0,
				'unencrypted_size' => $isEncrypted ? $info['size'] : 0,
			]);
			$stage = $folder->get($stageName);
			if (!$stage instanceof File) {
				throw new \RuntimeException("Nextcloud does not see the restored file $relPath");
			}
			$in = $stage->fopen('rb');
			if (!is_resource($in)) {
				throw new \RuntimeException("Nextcloud cannot read the restored file $relPath");
			}
			try {
				$this->writeContent($folder, $relPath, self::chunks($in, $relPath), $isEncrypted ? $info['size'] : (int)$entry['s'], (int)$entry['m']);
			} finally {
				fclose($in);
			}
		} finally {
			// Put there past the Files API, removed the same way: not to the trash.
			@unlink($stageFile);
			self::removeTree($stageKeys);
			try {
				$storage->getCache()->remove(trim($folder->getInternalPath() . '/' . $stageName, '/'));
			} catch (\Throwable) {
				// a cache entry without a file: the next scan of this folder removes it
			}
		}
	}

	/** A folder of key files this restore made, with everything in it. */
	private static function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
	}

	/** Whether the Default Encryption Module uses one master key (its default) rather than keys per user. */
	private static function usesMasterKey(): bool {
		try {
			$value = \OCP\Server::get(\OCP\IAppConfig::class)->getValueString('encryption', 'useMasterKey', '1');
		} catch (\Throwable) {
			$value = \OCP\Server::get(\OCP\IAppConfig::class)->getValueBool('encryption', 'useMasterKey', true) ? '1' : '0';
		}
		return !in_array(strtolower(trim($value)), ['0', 'false', 'no'], true);
	}

	/**
	 * The content of a stream in parts; a read error (a wrong key or version) is a damaged file.
	 *
	 * @param resource $in
	 * @return \Generator<string>
	 */
	private static function chunks($in, string $relPath): \Generator {
		while (!feof($in)) {
			try {
				$data = fread($in, 1048576);
			} catch (\Throwable $e) {
				throw new \OCA\NgBackup\Repository\RepositoryException("Nextcloud cannot decrypt the restored file $relPath: " . $e->getMessage(), 0, $e);
			}
			if ($data === false) {
				throw new \OCA\NgBackup\Repository\RepositoryException("Nextcloud cannot decrypt the restored file $relPath");
			}
			if ($data !== '') {
				yield $data;
			}
		}
	}

	/** @param iterable<string> $chunks */
	private function writeRaw(string $file, iterable $chunks, int $size, string $what): void {
		if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0770, true) && !is_dir(dirname($file))) {
			throw new \RuntimeException("Cannot write $what (disk full, or no permission?)");
		}
		$out = fopen($file, 'xb');
		if ($out === false) {
			throw new \RuntimeException("Cannot write $what (disk full, or no permission?)");
		}
		$written = 0;
		foreach ($chunks as $data) {
			if (fwrite($out, $data) !== strlen($data)) {
				fclose($out);
				throw new \RuntimeException("Write failed for $what");
			}
			$written += strlen($data);
		}
		if (!fclose($out)) {
			throw new \RuntimeException("Write failed for $what");
		}
		if ($written !== $size) {
			throw new \OCA\NgBackup\Repository\RepositoryException("Size mismatch restoring $what");
		}
	}

	/**
	 * Write content to $folder/<name of $relPath>: to a temporary name first, renamed into place
	 * (or put into the existing file, whose content becomes a version) only when all of it was
	 * read and has the expected size.
	 *
	 * @param iterable<string> $chunks
	 */
	private function writeContent(Folder $folder, string $relPath, iterable $chunks, int $size, int $mtime): void {
		$name = basename($relPath);
		$tmpName = '.' . $name . '.ngb-restore-' . bin2hex(random_bytes(3));
		$tmp = $folder->newFile($tmpName);
		try {
			$out = $tmp->fopen('wb');
			$written = 0;
			foreach ($chunks as $data) {
				if (fwrite($out, $data) !== strlen($data)) {
					throw new \RuntimeException('Write failed for ' . $relPath);
				}
				$written += strlen($data);
			}
			if (!fclose($out)) {
				throw new \RuntimeException('Write failed for ' . $relPath);
			}
			if ($written !== $size) {
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
				$existing->touch($mtime);
			} else {
				$tmp->move($folder->getPath() . '/' . $name);
				$folder->get($name)->touch($mtime);
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
