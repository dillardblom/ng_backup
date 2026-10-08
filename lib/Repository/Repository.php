<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Crypto\StreamCipher;

/**
 * Content-addressed, encrypted, deduplicating repository.
 *
 * Layout on the backend:
 *   config                 plaintext JSON: format version, repository id, wrapped master key
 *   packs/xx/<packId>      encrypted blobs + encrypted pack header
 *   index/<packId>         encrypted list of the blobs in that pack
 *   snapshots/<snapId>     encrypted JSON lines: one meta line, then one line per file
 *
 * Files are read as streams and cut into blobs of BLOB_SIZE; only one blob and one pack are in
 * memory at a time. A blob that already exists (same keyed hash) is not stored again.
 */
final class Repository {
	public const FORMAT = 1;
	public const BLOB_SIZE = 4 * 1048576;
	private const FLAG_DEFLATE = 1;
	/** Reads per blob in a deep verify before it is reported as unreadable (not corrupted). */
	private const VERIFY_READ_ATTEMPTS = 3;
	/** A gap up to this size in an open pack stream is read past instead of opening the pack again. */
	private const SKIP_MAX = 8 * 1048576;

	private StreamCipher $cipher;
	private BlobIndex $index;
	private ?PackWriter $runPacks = null;
	private ?int $maxBytes = null;
	private Catalog $catalog;
	/** @var resource|null open read stream of the pack read last, positioned at $packStreamPos */
	private $packStream = null;
	private string $packStreamId = '';
	private int $packStreamPos = 0;

	private function __construct(
		private IBackend $backend,
		private KeyRing $keys,
		private string $repositoryId,
	) {
		$this->cipher = new StreamCipher($keys);
		$this->index = new BlobIndex($backend, $this->cipher);
	}

	public static function init(IBackend $backend, #[\SensitiveParameter] string $passphrase): self {
		$keys = KeyRing::generate();
		return self::initWithKey($backend, $keys, $keys->wrap($passphrase));
	}

	/**
	 * Create a repository with an existing master key. $wrappedKey is the passphrase-wrapped
	 * copy (KeyRing::wrap) stored in the repository config, so the repository can be opened
	 * with the passphrase from the recovery kit even when the server is gone.
	 */
	public static function initWithKey(IBackend $backend, KeyRing $keys, array $wrappedKey, ?ICatalogAnchor $anchor = null): self {
		if ($backend->exists('config')) {
			throw new RepositoryException('A repository already exists at this location');
		}
		$id = bin2hex(random_bytes(16));
		$config = ['format' => self::FORMAT, 'id' => $id, 'created' => gmdate('c'), 'key' => $wrappedKey,
			'check' => base64_encode((new StreamCipher($keys))->encryptString($id, 'config-check'))];
		self::putString($backend, 'config', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
		$repo = new self($backend, $keys, $id);
		$repo->loadCatalog($anchor);
		return $repo;
	}

	/** Open with the master key the server holds (scheduled backups, no passphrase needed). */
	public static function openWithKey(IBackend $backend, KeyRing $keys, ?IIndexCache $cache = null, ?ICatalogAnchor $anchor = null): self {
		$config = self::readConfig($backend);
		try {
			$ok = isset($config['check']) && (new StreamCipher($keys))->decryptString((string)base64_decode($config['check'], true), 'config-check') === $config['id'];
		} catch (\OCA\NgBackup\Crypto\CryptoException) {
			$ok = false;
		}
		if (!$ok) {
			throw new RepositoryException('This repository was created with a different key');
		}
		$repo = new self($backend, $keys, $config['id']);
		if ($cache !== null) {
			$repo->index->setCache($cache, $config['id']);
		}
		$repo->index->load();
		$repo->loadCatalog($anchor);
		return $repo;
	}

	private function loadCatalog(?ICatalogAnchor $anchor): void {
		$this->catalog = new Catalog($this->backend, $this->cipher, $this->repositoryId, $anchor);
		$this->catalog->load(
			fn () => array_map('basename', $this->backend->listFiles('snapshots')),
			fn () => array_map('basename', $this->backend->listFiles('trash/info')),
			fn () => $this->backend->list('users'));
	}

	/** Refuse uploads that would make the stored data larger than this (null = no limit). */
	public function setMaxBytes(?int $maxBytes): void {
		$this->maxBytes = $maxBytes;
	}

	public function storedBytes(): int {
		return $this->index->storedBytes();
	}

	/** Current catalog generation (for status and tests). */
	public function catalogGeneration(): int {
		return $this->catalog->gen;
	}

	/** Index files downloaded when this repository was opened (0 when the local cache was complete). */
	public function indexDownloads(): int {
		return $this->index->downloaded;
	}

	private static function readConfig(IBackend $backend): array {
		try {
			$fh = $backend->get('config');
		} catch (BackendException) {
			throw new RepositoryException('No repository at this location');
		}
		$config = json_decode((string)stream_get_contents($fh), true, 512, JSON_THROW_ON_ERROR);
		fclose($fh);
		if (($config['format'] ?? 0) !== self::FORMAT) {
			throw new RepositoryException('Unsupported repository format');
		}
		return $config;
	}

	/** Replace the passphrase-wrapped key slots in this repository's config (after a slot change). */
	public function updateWrappedKey(array $wrappedKey): void {
		$config = self::readConfig($this->backend);
		$config['key'] = $wrappedKey;
		self::putString($this->backend, 'config', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}

	public function id(): string {
		return $this->repositoryId;
	}

	public static function open(IBackend $backend, #[\SensitiveParameter] string $passphrase): self {
		$config = self::readConfig($backend);
		$repo = new self($backend, KeyRing::unwrapAny($config['key'], $passphrase), $config['id']);
		$repo->index->load();
		$repo->loadCatalog(null); // disaster recovery on a new server: no anchor yet, trust the newest valid chain
		return $repo;
	}

	/**
	 * Back up a local directory tree. Files whose size and mtime match the parent snapshot are not
	 * read again; their blob list is reused.
	 *
	 * @return array{snapshot:string, files:int, reused:int, read:int, newBlobs:int, dupBlobs:int, uploaded:int}
	 */
	public function backupDirectory(string $source, ?string $parent = null, string $label = ''): array {
		$source = rtrim($source, '/');
		$previous = $parent !== null ? $this->loadEntries($parent) : [];
		$packs = new PackWriter($this->backend, $this->cipher, $this->index, 32 * 1048576, $this->maxBytes);
		$stats = ['files' => 0, 'reused' => 0, 'read' => 0, 'newBlobs' => 0, 'dupBlobs' => 0, 'uploaded' => 0];

		$tree = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		$snapshotId = bin2hex(random_bytes(16));
		fwrite($tree, json_encode(['snapshot' => $snapshotId, 'time' => gmdate('c'), 'parent' => $parent, 'label' => $label, 'source' => $source], JSON_THROW_ON_ERROR) . "\n");

		foreach ($this->walk($source) as $relative => $info) {
			$stats['files']++;
			$prev = $previous[$relative] ?? null;
			if ($prev !== null && $prev['s'] === $info->getSize() && $prev['m'] === $info->getMTime()) {
				$blobs = $prev['b'];
				$stats['reused']++;
			} else {
				$blobs = $this->readStable($info->getPathname(), $packs, $stats);
				$stats['read']++;
			}
			fwrite($tree, json_encode(['p' => $relative, 's' => $info->getSize(), 'm' => $info->getMTime(), 'b' => $blobs], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}
		$packs->flush();

		// The snapshot is written last: it only references blobs whose packs are already stored.
		rewind($tree);
		$encrypted = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		$this->cipher->encrypt($tree, $encrypted, 'snapshot:' . $snapshotId);
		rewind($encrypted);
		$this->backend->put('snapshots/' . $snapshotId, $encrypted);
		fclose($tree);
		$this->catalog->snapshots[$snapshotId] = true;
		$this->catalog->write();

		return ['snapshot' => $snapshotId] + $stats;
	}

	/**
	 * Restore a snapshot (or only paths starting with $prefix) into a local directory.
	 *
	 * @param bool $stripPrefix write "$target/<path without $prefix>" instead of
	 *             "$target/<full logical path>" (disaster recovery: restoring e.g. "data/" or
	 *             "config/" directly into the real data/config directory, not a subdirectory
	 *             named after the prefix). Ignored when $prefix is '' (nothing to strip).
	 */
	public function restore(string $snapshotId, string $target, string $prefix = '', bool $stripPrefix = false, ?callable $heartbeat = null): int {
		if ($prefix !== '' && !self::isSafePath(rtrim($prefix, '/'))) {
			throw new RepositoryException('Invalid path prefix');
		}
		$count = 0;
		foreach ($this->streamEntries($snapshotId) as $entry) {
			if ($prefix !== '' && !str_starts_with($entry['p'], $prefix)) {
				continue;
			}
			$relPath = ($stripPrefix && $prefix !== '') ? substr($entry['p'], strlen(rtrim($prefix, '/')) + 1) : $entry['p'];
			$dest = rtrim($target, '/') . '/' . $relPath;
			if (!is_dir(dirname($dest))) {
				mkdir(dirname($dest), 0700, true);
			}
			// Defence in depth: the resolved directory must stay below the target (no symlink escapes).
			$base = realpath($target);
			$dir = realpath(dirname($dest));
			if ($base === false || $dir === false || ($dir !== $base && !str_starts_with($dir, $base . '/')) || is_link($dest)) {
				throw new RepositoryException('Refusing to write outside the restore directory: ' . $entry['p']);
			}
			// Write next to the destination and rename after all blobs verified: at most one file
			// is ever present twice.
			$tmp = $dest . '.ngb-restore-' . bin2hex(random_bytes(6));
			$out = @fopen($tmp, 'xb'); // fails if anything (including a symlink) already exists there
			if ($out === false) {
				throw new RepositoryException('Cannot create a temporary file for ' . $entry['p']);
			}
			foreach ($entry['b'] as $id) {
				fwrite($out, $this->loadBlob($id));
				if ($heartbeat !== null) {
					$heartbeat();
				}
			}
			fclose($out);
			if (filesize($tmp) !== $entry['s']) {
				unlink($tmp);
				throw new RepositoryException('Size mismatch restoring ' . $entry['p']);
			}
			if (!rename($tmp, $dest)) {
				@unlink($tmp);
				throw new RepositoryException('Cannot put the restored file in place: ' . $entry['p']);
			}
			if (!touch($dest, $entry['m'])) {
				throw new RepositoryException('Cannot set the modification time of ' . $entry['p']);
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Start writing a generated stream (e.g. a table dump) as blobs. Call flushPacks() when the
	 * run is done, so the last pack is uploaded before anything references it.
	 */
	public function blobWriter(array &$stats): BlobWriter {
		$this->runPacks ??= new PackWriter($this->backend, $this->cipher, $this->index, 32 * 1048576, $this->maxBytes);
		$packs = $this->runPacks;
		return new BlobWriter(function (string $data) use ($packs, &$stats): string {
			return $this->storeBlob($data, $packs, $stats);
		});
	}

	/** See BlobIndex::holdCache(): no database writes until releaseIndexCache(). */
	public function holdIndexCache(): void {
		$this->index->holdCache();
	}

	public function releaseIndexCache(): void {
		$this->index->releaseCache();
	}

	public function flushPacks(): void {
		$this->runPacks?->flush();
	}

	/**
	 * Read a blob stream back as lines (lines may span blob boundaries).
	 *
	 * @param list<string> $blobIds
	 * @return \Generator<string>
	 */
	public function readLines(array $blobIds): \Generator {
		$carry = '';
		foreach ($blobIds as $id) {
			$data = $carry . $this->loadBlob($id);
			$lines = explode("\n", $data);
			$carry = array_pop($lines);
			yield from $lines;
		}
		if ($carry !== '') {
			yield $carry;
		}
	}

	/** Store one blob of file data in the current run (deduplicated); returns its id. */
	public function storeData(string $data, array &$stats): string {
		$this->runPacks ??= new PackWriter($this->backend, $this->cipher, $this->index, 32 * 1048576, $this->maxBytes);
		return $this->storeBlob($data, $this->runPacks, $stats);
	}

	/**
	 * Write a snapshot from a meta record and the tree lines (JSON, one per file). Must be called
	 * after flushPacks(), so the snapshot only references stored blobs.
	 *
	 * @param iterable<string> $lines
	 */
	public function writeSnapshot(string $snapshotId, array $meta, iterable $lines): void {
		$this->putSnapshot($snapshotId, $meta, $lines);
		$this->recordSnapshot($snapshotId);
	}

	/**
	 * Store a snapshot object without touching the catalog. Use recordSnapshot() afterwards, from a
	 * repository opened inside the catalog lock, so the catalog write builds on the latest generation.
	 *
	 * @param iterable<string> $lines
	 */
	public function putSnapshot(string $snapshotId, array $meta, iterable $lines): void {
		$tree = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		fwrite($tree, json_encode(['snapshot' => $snapshotId] + $meta, JSON_THROW_ON_ERROR) . "\n");
		foreach ($lines as $line) {
			if ($line !== '') {
				fwrite($tree, $line . "\n");
			}
		}
		rewind($tree);
		$encrypted = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		$this->cipher->encrypt($tree, $encrypted, 'snapshot:' . $snapshotId);
		fclose($tree);
		rewind($encrypted);
		$this->backend->put('snapshots/' . $snapshotId, $encrypted);
	}

	public function recordSnapshot(string $snapshotId): void {
		$this->catalog->snapshots[$snapshotId] = true;
		$this->catalog->write();
	}

	/** @return array<string, array{s:int, m:int, b:list<string>}> */
	public function snapshotEntries(string $snapshotId): array {
		return $this->loadEntries($snapshotId);
	}

	/**
	 * @param list<string> $blobIds
	 * @return \Generator<string> verified blob contents, in order
	 */
	public function readBlobs(array $blobIds): \Generator {
		foreach ($blobIds as $id) {
			yield $this->loadBlob($id);
		}
	}

	/** Relative path of non-empty segments, without ".", "..", NUL, backslash or control characters. */
	public static function isSafePath(string $path): bool {
		if ($path === '' || str_starts_with($path, '/') || preg_match('/[\x00-\x1f\\\\]/', $path)) {
			return false;
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}
		return true;
	}

	/** Small encrypted objects (manifests). */
	public function putObject(string $path, string $data): void {
		self::putString($this->backend, $path, $this->cipher->encryptString($data, 'object:' . $path));
	}

	public function getObject(string $path): string {
		$fh = $this->backend->get($path);
		$data = $this->cipher->decryptString((string)stream_get_contents($fh), 'object:' . $path);
		fclose($fh);
		return $data;
	}

	/**
	 * Move a snapshot (and its database dump) to the trash on the location. Its data stays in
	 * use until purgeTrash() removes it after the deletion delay, so a malicious or mistaken
	 * cleanup can be undone with untrash() within that time.
	 */
	public function forget(string $snapshotId, string $by = ''): void {
		$meta = $this->snapshotMeta($snapshotId);
		$dbInTrash = null;
		if (isset($meta['db']) && is_string($meta['db']) && $this->backend->exists($meta['db'])) {
			// Its encryption is bound to its path (object:db/...), which changes on a move, so it
			// must be decrypted and re-encrypted under the new path, not moved as raw bytes.
			$dbInTrash = 'trash/' . $meta['db'];
			$this->putObject($dbInTrash, $this->getObject($meta['db']));
		}
		// Copy first, switch the catalog, delete the originals last: a crash at any point leaves
		// either the old state or the new one plus unreferenced leftovers, never a catalog entry
		// pointing at a missing object. The snapshot object is bound to its id (not its path),
		// so it can be copied as raw bytes.
		$this->copyRaw('snapshots/' . $snapshotId, 'trash/snapshots/' . $snapshotId);
		$this->putObject('trash/info/' . $snapshotId, json_encode(['forgottenAt' => time(), 'by' => $by, 'db' => $meta['db'] ?? null,
			'dbInTrash' => $dbInTrash, 'time' => $meta['time'] ?? null, 'label' => $meta['label'] ?? ''], JSON_THROW_ON_ERROR));
		unset($this->catalog->snapshots[$snapshotId]);
		$this->catalog->trash[$snapshotId] = true;
		$this->catalog->write();
		if ($dbInTrash !== null) {
			$this->backend->delete($meta['db']);
		}
		$this->backend->delete('snapshots/' . $snapshotId);
	}

	/** Undo forget(): move a snapshot back from the trash. */
	public function untrash(string $snapshotId): void {
		$info = $this->trashInfo($snapshotId);
		$restoreDb = $info['dbInTrash'] !== null && $this->backend->exists($info['dbInTrash']);
		if ($restoreDb) {
			$this->putObject($info['db'], $this->getObject($info['dbInTrash']));
		}
		// Same order as forget(): copy, switch the catalog, then delete the trash copies.
		$this->copyRaw('trash/snapshots/' . $snapshotId, 'snapshots/' . $snapshotId);
		$this->catalog->snapshots[$snapshotId] = true;
		unset($this->catalog->trash[$snapshotId]);
		$this->catalog->write();
		if ($restoreDb) {
			$this->backend->delete($info['dbInTrash']);
		}
		$this->backend->delete('trash/snapshots/' . $snapshotId);
		$this->backend->delete('trash/info/' . $snapshotId);
	}

	/** Copy an object as raw bytes, buffered locally (remote read streams cannot be uploaded directly everywhere). */
	private function copyRaw(string $from, string $to): void {
		$in = $this->backend->get($from);
		$buffer = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		if ($buffer === false) {
			fclose($in);
			throw new RepositoryException("Cannot buffer $from");
		}
		try {
			if (stream_copy_to_stream($in, $buffer) === false) {
				throw new RepositoryException("Cannot read $from");
			}
		} finally {
			fclose($in);
		}
		rewind($buffer);
		$this->backend->put($to, $buffer);
	}

	/** Record a user_migration export's manifest in the catalog (rollback-protected like a snapshot). */
	public function recordUserExport(string $manifestPath): void {
		$this->catalog->userExports[$manifestPath] = true;
		$this->catalog->write();
	}

	/**
	 * User export manifests according to the verified catalog, newest first. A manifest the
	 * catalog lists but the location no longer has means it was deleted or hidden behind NG
	 * Backup's back, same as a missing snapshot (see listSnapshots()).
	 *
	 * @return list<string>
	 */
	public function listUserExports(?string $uid = null): array {
		$prefix = $uid !== null ? 'users/' . $uid . '/' : 'users/';
		$out = [];
		foreach (array_keys($this->catalog->userExports) as $path) {
			if (!str_starts_with($path, $prefix)) {
				continue;
			}
			if (!$this->backend->exists($path)) {
				throw new RepositoryException("User export $path is missing on the location (deleted or hidden outside NG Backup)");
			}
			$out[] = $path;
		}
		sort($out, SORT_STRING);
		return array_reverse($out);
	}

	/** Whether $manifestPath is a user export the verified catalog actually recorded. */
	public function isRecordedUserExport(string $manifestPath): bool {
		return isset($this->catalog->userExports[$manifestPath]);
	}

	/** Permanently delete a user export. Its blobs are freed by the next prune() like any other. */
	public function forgetUserExport(string $manifestPath): void {
		if (!isset($this->catalog->userExports[$manifestPath])) {
			throw new RepositoryException("$manifestPath is not a recorded user export");
		}
		unset($this->catalog->userExports[$manifestPath]);
		$this->catalog->write();
		$this->backend->delete($manifestPath);
	}

	/** @return array<string, array{forgottenAt:int, by:string, db:?string, dbInTrash:?string, time:?string, label:string}> */
	public function trash(): array {
		$out = [];
		foreach (array_keys($this->catalog->trash) as $id) {
			try {
				$out[$id] = $this->trashInfo($id);
			} catch (\Throwable) {
				throw new RepositoryException("Trash entry $id listed in the catalog is missing on the location (possible tampering)");
			}
		}
		return $out;
	}

	/** Permanently delete trash entries older than $delaySeconds. @return list<string> purged snapshot ids */
	public function purgeTrash(int $delaySeconds): array {
		$doomed = [];
		foreach ($this->trash() as $id => $info) {
			if (time() - $info['forgottenAt'] < $delaySeconds) {
				continue;
			}
			$doomed[$id] = $info;
			unset($this->catalog->trash[$id]);
		}
		if ($doomed === []) {
			return [];
		}
		$this->catalog->write();
		foreach ($doomed as $id => $info) {
			if ($info['dbInTrash'] !== null) {
				$this->backend->delete($info['dbInTrash']);
			}
			$this->backend->delete('trash/snapshots/' . $id);
			$this->backend->delete('trash/info/' . $id);
		}
		return array_keys($doomed);
	}

	private function trashInfo(string $snapshotId): array {
		return json_decode($this->getObject('trash/info/' . $snapshotId), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Delete packs no snapshot or user export uses any more, and repack packs where more than
	 * $repackThreshold of the bytes are unused. Must not run while a backup to this repository
	 * is in progress (its blobs are not referenced by a snapshot yet); callers hold the lock.
	 *
	 * @return array{packs:int, deleted:int, repacked:int, freedBytes:int, keptBlobs:int, unusedBlobs:int}
	 */
	public function prune(bool $dryRun = false, float $repackThreshold = 0.5, array $ignoreSnapshots = []): array {
		// Deleting is decided on the authenticated index files on the location, never on the local cache.
		$this->index->load(true);
		$used = [];
		$ignore = array_flip($ignoreSnapshots); // e.g. snapshots a dry run would forget first
		foreach ($this->listSnapshots() as $sid) {
			if (isset($ignore[$sid])) {
				continue;
			}
			foreach ($this->streamEntries($sid) as $e) {
				foreach ($e['b'] as $b) {
					$used[$b] = true;
				}
			}
			$meta = $this->snapshotMeta($sid);
			if (isset($meta['db']) && $this->backend->exists($meta['db'])) {
				foreach (json_decode($this->getObject($meta['db']), true, 512, JSON_THROW_ON_ERROR)['tables'] as $t) {
					foreach ($t['blobs'] as $b) {
						$used[$b] = true;
					}
				}
			}
		}
		// Snapshots in the trash keep their data until purgeTrash() removed them.
		foreach ($this->trash() as $sid => $info) {
			foreach ($this->streamEntries($sid, 'trash/snapshots/') as $e) {
				foreach ($e['b'] as $b) {
					$used[$b] = true;
				}
			}
			if ($info['dbInTrash'] !== null && $this->backend->exists($info['dbInTrash'])) {
				foreach (json_decode($this->getObject($info['dbInTrash']), true, 512, JSON_THROW_ON_ERROR)['tables'] as $t) {
					foreach ($t['blobs'] as $b) {
						$used[$b] = true;
					}
				}
			}
		}
		// listUserExports() throws when a recorded export's manifest is missing: then stop, since
		// freeing its blobs would turn a tampering or storage alarm into irreversible deletion.
		$exports = array_unique(array_merge($this->listUserExports(), $this->backend->list('users')));
		foreach ($exports as $path) {
			foreach (json_decode($this->getObject($path), true, 512, JSON_THROW_ON_ERROR)['entries'] as $e) {
				foreach ($e['b'] ?? [] as $b) {
					$used[$b] = true;
				}
			}
		}

		$stats = ['packs' => 0, 'deleted' => 0, 'repacked' => 0, 'freedBytes' => 0, 'keptBlobs' => count($used), 'unusedBlobs' => 0];
		$packs = $this->index->packs();
		$stats['packs'] = count($packs);
		$writer = null;
		$toRemove = [];
		foreach ($packs as $packId => $blobs) {
			$total = 0;
			$unused = 0;
			foreach ($blobs as $b) {
				$total += $b['length'];
				if (!isset($used[$b['id']])) {
					$unused += $b['length'];
					$stats['unusedBlobs']++;
				}
			}
			if ($unused === 0 || ($unused < $total && $unused / $total <= $repackThreshold)) {
				continue;
			}
			$stats['freedBytes'] += $unused;
			if ($unused === $total) {
				$stats['deleted']++;
			} else {
				$stats['repacked']++;
				if (!$dryRun) {
					// No size limit here: repacking is how space is freed.
					$writer ??= new PackWriter($this->backend, $this->cipher, $this->index);
					$path = 'packs/' . substr($packId, 0, 2) . '/' . $packId;
					foreach ($blobs as $b) {
						if (isset($used[$b['id']])) {
							[, , , $raw, $flags] = $this->index->get($b['id']);
							$writer->addRaw($b['id'], $this->backend->getRange($path, $b['offset'], $b['length']), $raw, $flags);
						}
					}
				}
			}
			$toRemove[] = $packId;
		}
		if ($dryRun) {
			return $stats;
		}
		// New packs and their index entries are durable before any old pack is removed.
		$writer?->flush();
		foreach ($toRemove as $packId) {
			$this->index->removePack($packId);
			$this->backend->delete('packs/' . substr($packId, 0, 2) . '/' . $packId);
		}
		return $stats;
	}

	/**
	 * Snapshots according to the verified catalog. A snapshot the catalog lists but the location
	 * no longer has means it was deleted or hidden behind NG Backup's back.
	 *
	 * @return list<string>
	 */
	public function listSnapshots(): array {
		$present = array_flip(array_map('basename', $this->backend->listFiles('snapshots')));
		$listed = array_keys($this->catalog->snapshots);
		foreach ($listed as $id) {
			if (!isset($present[$id])) {
				throw new RepositoryException("Snapshot $id is missing on the location (deleted or hidden outside NG Backup)");
			}
		}
		sort($listed);
		return $listed;
	}

	public function blobCount(): int {
		return $this->index->count();
	}

	/**
	 * Read a file into blobs and make sure it did not change while reading (size, mtime, ctime
	 * before and after). A file that keeps changing is an error rather than a torn backup.
	 *
	 * @return list<string>
	 */
	private function readStable(string $path, PackWriter $packs, array &$stats): array {
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$before = self::fileVersion($path);
			$blobs = [];
			$fh = fopen($path, 'rb');
			while (($data = self::readFull($fh, self::BLOB_SIZE)) !== '') {
				$blobs[] = $this->storeBlob($data, $packs, $stats);
			}
			fclose($fh);
			if (self::fileVersion($path) === $before) {
				return $blobs;
			}
			$stats['retried'] = ($stats['retried'] ?? 0) + 1;
		}
		throw new RepositoryException('File keeps changing while being read: ' . $path);
	}

	/** Cheap file identity: changes when the content may have changed. */
	public static function fileVersion(string $path): string {
		clearstatcache(true, $path);
		$st = @stat($path);
		return $st === false ? 'missing' : implode(':', [$st['size'], $st['mtime'], $st['ctime'], $st['ino']]);
	}

	private function storeBlob(string $data, PackWriter $packs, array &$stats): string {
		$id = $this->keys->blobId($data);
		if ($this->index->has($id)) {
			$stats['dupBlobs'] = ($stats['dupBlobs'] ?? 0) + 1;
			return $id;
		}
		$flags = 0;
		$payload = $data;
		$deflated = gzdeflate($data, 6);
		if ($deflated !== false && strlen($deflated) < strlen($data) * 0.95) {
			$payload = $deflated;
			$flags |= self::FLAG_DEFLATE;
		}
		$stats['uploaded'] = ($stats['uploaded'] ?? 0) + $packs->add($id, $this->cipher->encryptString($payload, 'blob:' . $id), strlen($data), $flags);
		$stats['newBlobs'] = ($stats['newBlobs'] ?? 0) + 1;
		return $id;
	}

	private function loadBlob(string $id): string {
		[$pack, $offset, $length] = $this->index->get($id);
		return $this->decodeBlob($id, $this->readPack($pack, $offset, $length));
	}

	/**
	 * Bytes of a pack. Restores and deep verifies read the blobs of a snapshot in the order they
	 * were written, so the stream of the pack read last is kept open and read on from where it
	 * is: one request per pack instead of one per blob (a round trip each, and on most remote
	 * storages a new connection or file handle too). Anything else opens the pack at the offset,
	 * as a single read always did.
	 */
	private function readPack(string $pack, int $offset, int $length): string {
		if ($this->packStream !== null && $this->packStreamId === $pack && $offset >= $this->packStreamPos && $offset - $this->packStreamPos <= self::SKIP_MAX) {
			try {
				while ($this->packStreamPos < $offset) {
					$this->readExactly(min($offset - $this->packStreamPos, 1048576), $pack);
				}
				return $this->readExactly($length, $pack);
			} catch (\Exception) {
				// The open stream broke (e.g. a timeout); open the pack again below.
			}
		}
		$this->closePackStream();
		$fh = $this->backend->get('packs/' . substr($pack, 0, 2) . '/' . $pack);
		$this->packStream = $fh;
		$this->packStreamId = $pack;
		$this->packStreamPos = 0;
		try {
			if ($offset > 0 && fseek($fh, $offset) !== 0) {
				throw new RepositoryException('Seek failed in pack ' . $pack);
			}
			$this->packStreamPos = $offset;
			return $this->readExactly($length, $pack);
		} catch (\Exception $e) {
			$this->closePackStream();
			throw $e;
		}
	}

	/** Read $length bytes from the open pack stream; a short read closes it. */
	private function readExactly(int $length, string $pack): string {
		$fh = $this->packStream ?? throw new RepositoryException('No open pack stream');
		$data = '';
		while (strlen($data) < $length) {
			$chunk = fread($fh, min($length - strlen($data), 1048576));
			if ($chunk === false || $chunk === '') {
				$this->closePackStream();
				throw new RepositoryException('Short read in pack ' . $pack);
			}
			$data .= $chunk;
		}
		$this->packStreamPos += $length;
		return $data;
	}

	private function closePackStream(): void {
		$fh = $this->packStream;
		$this->packStream = null;
		if ($fh !== null) {
			@fclose($fh);
		}
	}

	public function __destruct() {
		$this->closePackStream();
	}

	/** Decrypts and checks a blob read from its pack; throws when it is corrupted or tampered with. */
	private function decodeBlob(string $id, string $encrypted): string {
		[, , , $raw, $flags] = $this->index->get($id);
		$payload = $this->cipher->decryptString($encrypted, 'blob:' . $id);
		$data = ($flags & self::FLAG_DEFLATE) ? gzinflate($payload) : $payload;
		if ($data === false || strlen($data) !== $raw || $this->keys->blobId($data) !== $id) {
			throw new RepositoryException('Blob verification failed: ' . $id);
		}
		return $data;
	}

	/** @return array<string, array{s:int, m:int, b:list<string>}> */
	private function loadEntries(string $snapshotId): array {
		$map = [];
		foreach ($this->streamEntries($snapshotId) as $e) {
			$map[$e['p']] = ['s' => $e['s'], 'm' => $e['m'], 'b' => $e['b']];
		}
		return $map;
	}

	/** @return \Generator<array{p:string, s:int, m:int, b:list<string>}> entries of a snapshot, in stored order */
	public function entries(string $snapshotId): \Generator {
		return $this->streamEntries($snapshotId);
	}

	/**
	 * Checksum audit of a snapshot: every blob it references (files and the database dump, when
	 * the snapshot has one) must be in the index, freshly re-downloaded and re-authenticated from
	 * the location rather than trusted from the local cache (same reasoning as prune()), and its
	 * pack must still be present. With $deep, each blob is also downloaded and decrypted, which
	 * verifies its AEAD authentication tag and its content hash against the blob id (loadBlob()
	 * already does both on every real read -- this just does it for everything in the snapshot,
	 * deliberately, rather than relying on it to come up during a later restore). A blob
	 * referenced by more than one file is only checked once; a missing database manifest is a
	 * failure, not something to silently skip over.
	 *
	 * @param ?callable $heartbeat called after every blob (shallow) or every blob read (deep);
	 *        callers use it to keep a time-boxed lease alive during a large audit
	 * @return array{filesChecked:int, blobsChecked:int, bytesChecked:int, missing:list<string>, failed:list<string>, unreadable:list<string>, readError:string}
	 */
	public function verify(string $snapshotId, bool $deep = false, ?callable $heartbeat = null): array {
		$heartbeat ??= static function (): void {
		};
		$this->index->load(true);
		$stats = ['filesChecked' => 0, 'blobsChecked' => 0, 'bytesChecked' => 0, 'missing' => [], 'failed' => [], 'unreadable' => [], 'readError' => ''];
		$seen = [];
		$packSeen = []; // pack id => exists on the backend; avoids one exists() call per blob
		foreach ($this->streamEntries($snapshotId) as $entry) {
			$stats['filesChecked']++;
			foreach ($entry['b'] as $id) {
				if (isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
				$this->verifyBlob($id, $deep, $stats, $packSeen);
				$heartbeat();
			}
		}
		$meta = $this->snapshotMeta($snapshotId);
		if (isset($meta['db']) && is_string($meta['db'])) {
			if (!$this->backend->exists($meta['db'])) {
				$stats['missing'][] = $meta['db'];
			} else {
				$dbManifest = json_decode($this->getObject($meta['db']), true, 512, JSON_THROW_ON_ERROR);
				foreach ($dbManifest['tables'] as $info) {
					foreach ($info['blobs'] as $id) {
						if (isset($seen[$id])) {
							continue;
						}
						$seen[$id] = true;
						$this->verifyBlob($id, $deep, $stats, $packSeen);
						$heartbeat();
					}
				}
			}
		}
		return $stats;
	}

	/**
	 * Same checks as verify(), for a user export: every blob its manifest references must be in
	 * the freshly re-authenticated index with its pack present; $deep also decrypts each.
	 *
	 * @return array{filesChecked:int, blobsChecked:int, bytesChecked:int, missing:list<string>, failed:list<string>, unreadable:list<string>, readError:string}
	 */
	public function verifyUserExport(string $manifestPath, bool $deep = false, ?callable $heartbeat = null): array {
		if (!$this->isRecordedUserExport($manifestPath)) {
			throw new RepositoryException("$manifestPath is not a recorded user export for this location");
		}
		$heartbeat ??= static function (): void {
		};
		$this->index->load(true);
		$stats = ['filesChecked' => 0, 'blobsChecked' => 0, 'bytesChecked' => 0, 'missing' => [], 'failed' => [], 'unreadable' => [], 'readError' => ''];
		$seen = [];
		$packSeen = [];
		$manifest = json_decode($this->getObject($manifestPath), true, 512, JSON_THROW_ON_ERROR);
		foreach ($manifest['entries'] as $entry) {
			if (($entry['t'] ?? '') !== 'f') {
				continue;
			}
			$stats['filesChecked']++;
			foreach ($entry['b'] ?? [] as $id) {
				if (isset($seen[$id])) {
					continue;
				}
				$seen[$id] = true;
				$this->verifyBlob($id, $deep, $stats, $packSeen);
				$heartbeat();
			}
		}
		return $stats;
	}

	/** @param array<string, bool> $packSeen */
	private function verifyBlob(string $id, bool $deep, array &$stats, array &$packSeen): void {
		if (!$this->index->has($id)) {
			$stats['missing'][] = $id;
			return;
		}
		[$pack] = $this->index->get($id);
		$packExists = $packSeen[$pack] ??= $this->backend->exists('packs/' . substr($pack, 0, 2) . '/' . $pack);
		if (!$packExists) {
			$stats['missing'][] = $id;
			return;
		}
		$stats['blobsChecked']++;
		if (!$deep) {
			return;
		}
		// Reading and checking are kept apart: a read that fails is usually a network or
		// storage error, so it is retried and reported as unreadable (a pack that stays
		// unreadable may still be truncated). Only a blob that was read but does not
		// decrypt or match its id counts as failed: that is proof of damage.
		[, $offset, $length] = $this->index->get($id);
		$encrypted = null;
		for ($attempt = 1; $attempt <= self::VERIFY_READ_ATTEMPTS && $encrypted === null; $attempt++) {
			try {
				$encrypted = $this->readPack($pack, $offset, $length);
			} catch (\Exception $e) { // storage adapters throw their own exceptions; \Error (a bug) is not retried
				$stats['readError'] = $e->getMessage();
			}
		}
		if ($encrypted === null) {
			$stats['unreadable'][] = $id;
			return;
		}
		try {
			$stats['bytesChecked'] += strlen($this->decodeBlob($id, $encrypted));
		} catch (\Throwable) {
			$stats['failed'][] = $id;
		}
	}

	/** The meta record (first line) of a snapshot. */
	public function snapshotMeta(string $snapshotId): array {
		$plain = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		$fh = $this->backend->get('snapshots/' . $snapshotId);
		$this->cipher->decrypt($fh, $plain, 'snapshot:' . $snapshotId);
		fclose($fh);
		rewind($plain);
		$meta = json_decode((string)fgets($plain), true, 512, JSON_THROW_ON_ERROR);
		fclose($plain);
		return $meta;
	}

	/** @return \Generator<array{p:string, s:int, m:int, b:list<string>}> */
	private function streamEntries(string $snapshotId, string $dir = 'snapshots/'): \Generator {
		$plain = fopen('php://temp/maxmemory:' . (4 * 1048576), 'w+b');
		$fh = $this->backend->get($dir . $snapshotId);
		$this->cipher->decrypt($fh, $plain, 'snapshot:' . $snapshotId);
		fclose($fh);
		rewind($plain);
		fgets($plain); // meta line
		while (($line = fgets($plain)) !== false) {
			$entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($entry) || !is_string($entry['p'] ?? null) || !self::isSafePath($entry['p'])
				|| !is_int($entry['s'] ?? null) || !is_array($entry['b'] ?? null)) {
				throw new RepositoryException('Invalid entry in snapshot ' . $snapshotId);
			}
			yield $entry;
		}
		fclose($plain);
	}

	/** @return \Generator<string, \SplFileInfo> relative path => file, in a stable order */
	private function walk(string $root): \Generator {
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		$files = [];
		foreach ($it as $file) {
			if ($file->isFile()) {
				$files[substr($file->getPathname(), strlen($root) + 1)] = $file;
			}
		}
		ksort($files, SORT_STRING);
		yield from $files;
	}

	private static function putString(IBackend $backend, string $path, string $data): void {
		$s = fopen('php://memory', 'w+b');
		fwrite($s, $data);
		rewind($s);
		$backend->put($path, $s);
	}

	private static function readFull($fh, int $length): string {
		$buffer = '';
		while (strlen($buffer) < $length && !feof($fh)) {
			$buffer .= (string)fread($fh, $length - strlen($buffer));
		}
		return $buffer;
	}
}
