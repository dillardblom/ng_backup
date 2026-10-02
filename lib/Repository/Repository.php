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

	private StreamCipher $cipher;
	private BlobIndex $index;
	private ?PackWriter $runPacks = null;

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
	public static function initWithKey(IBackend $backend, KeyRing $keys, array $wrappedKey): self {
		if ($backend->exists('config')) {
			throw new RepositoryException('A repository already exists at this location');
		}
		$id = bin2hex(random_bytes(16));
		$config = ['format' => self::FORMAT, 'id' => $id, 'created' => gmdate('c'), 'key' => $wrappedKey,
			'check' => base64_encode((new StreamCipher($keys))->encryptString($id, 'config-check'))];
		self::putString($backend, 'config', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
		return new self($backend, $keys, $id);
	}

	/** Open with the master key the server holds (scheduled backups, no passphrase needed). */
	public static function openWithKey(IBackend $backend, KeyRing $keys, ?IIndexCache $cache = null): self {
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
		return $repo;
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
		$packs = new PackWriter($this->backend, $this->cipher, $this->index);
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

		return ['snapshot' => $snapshotId] + $stats;
	}

	/** Restore a snapshot (or only paths starting with $prefix) into a local directory. */
	public function restore(string $snapshotId, string $target, string $prefix = ''): int {
		$count = 0;
		foreach ($this->streamEntries($snapshotId) as $entry) {
			if ($prefix !== '' && !str_starts_with($entry['p'], $prefix)) {
				continue;
			}
			$dest = rtrim($target, '/') . '/' . $entry['p'];
			if (!is_dir(dirname($dest))) {
				mkdir(dirname($dest), 0700, true);
			}
			// Write next to the destination and rename after all blobs verified: at most one file
			// is ever present twice.
			$tmp = $dest . '.ngb-restore';
			$out = fopen($tmp, 'wb');
			foreach ($entry['b'] as $id) {
				fwrite($out, $this->loadBlob($id));
			}
			fclose($out);
			if (filesize($tmp) !== $entry['s']) {
				unlink($tmp);
				throw new RepositoryException('Size mismatch restoring ' . $entry['p']);
			}
			rename($tmp, $dest);
			touch($dest, $entry['m']);
			$count++;
		}
		return $count;
	}

	/**
	 * Start writing a generated stream (e.g. a table dump) as blobs. Call flushPacks() when the
	 * run is done, so the last pack is uploaded before anything references it.
	 */
	public function blobWriter(array &$stats): BlobWriter {
		$this->runPacks ??= new PackWriter($this->backend, $this->cipher, $this->index);
		$packs = $this->runPacks;
		return new BlobWriter(function (string $data) use ($packs, &$stats): string {
			return $this->storeBlob($data, $packs, $stats);
		});
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
		$this->runPacks ??= new PackWriter($this->backend, $this->cipher, $this->index);
		return $this->storeBlob($data, $this->runPacks, $stats);
	}

	/**
	 * Write a snapshot from a meta record and the tree lines (JSON, one per file). Must be called
	 * after flushPacks(), so the snapshot only references stored blobs.
	 *
	 * @param iterable<string> $lines
	 */
	public function writeSnapshot(string $snapshotId, array $meta, iterable $lines): void {
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
			$dbInTrash = 'trash/' . $meta['db'];
			$this->putObject($dbInTrash, $this->getObject($meta['db']));
		}
		// The snapshot object is bound to its id (not its path), so it can be copied as-is.
		$this->backend->put('trash/snapshots/' . $snapshotId, $this->backend->get('snapshots/' . $snapshotId));
		$this->putObject('trash/info/' . $snapshotId, json_encode(['forgottenAt' => time(), 'by' => $by, 'db' => $meta['db'] ?? null,
			'dbInTrash' => $dbInTrash, 'time' => $meta['time'] ?? null, 'label' => $meta['label'] ?? ''], JSON_THROW_ON_ERROR));
		if ($dbInTrash !== null) {
			$this->backend->delete($meta['db']);
		}
		$this->backend->delete('snapshots/' . $snapshotId);
	}

	/** Undo forget(): move a snapshot back from the trash. */
	public function untrash(string $snapshotId): void {
		$info = $this->trashInfo($snapshotId);
		if ($info['dbInTrash'] !== null && $this->backend->exists($info['dbInTrash'])) {
			$this->putObject($info['db'], $this->getObject($info['dbInTrash']));
			$this->backend->delete($info['dbInTrash']);
		}
		$this->backend->put('snapshots/' . $snapshotId, $this->backend->get('trash/snapshots/' . $snapshotId));
		$this->backend->delete('trash/snapshots/' . $snapshotId);
		$this->backend->delete('trash/info/' . $snapshotId);
	}

	/** @return array<string, array{forgottenAt:int, by:string, db:?string, dbInTrash:?string, time:?string, label:string}> */
	public function trash(): array {
		$out = [];
		foreach ($this->backend->list('trash/info') as $path) {
			$out[basename($path)] = $this->trashInfo(basename($path));
		}
		return $out;
	}

	/** Permanently delete trash entries older than $delaySeconds. @return list<string> purged snapshot ids */
	public function purgeTrash(int $delaySeconds): array {
		$purged = [];
		foreach ($this->trash() as $id => $info) {
			if (time() - $info['forgottenAt'] < $delaySeconds) {
				continue;
			}
			if ($info['dbInTrash'] !== null) {
				$this->backend->delete($info['dbInTrash']);
			}
			$this->backend->delete('trash/snapshots/' . $id);
			$this->backend->delete('trash/info/' . $id);
			$purged[] = $id;
		}
		return $purged;
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
		foreach ($this->backend->list('users') as $path) {
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

	/** @return list<string> */
	public function listSnapshots(): array {
		return array_map('basename', $this->backend->list('snapshots'));
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
		[$pack, $offset, $length, $raw, $flags] = $this->index->get($id);
		$encrypted = $this->backend->getRange('packs/' . substr($pack, 0, 2) . '/' . $pack, $offset, $length);
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
			yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
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
