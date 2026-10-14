<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\IWriteStreamStorage;

/**
 * IBackend on top of a Nextcloud storage object (from files_external or core), below a base
 * directory on that storage.
 */
final class StorageBackend implements IBackend {
	/** Object stores write whole objects atomically; other storages get a temp name + rename. */
	private bool $atomicPut;
	/** SFTP: read through SftpReadStream, which keeps several requests in flight. */
	private bool $sftpReads;
	/** Seconds without a request after which an SFTP connection is checked before it is used. */
	public const IDLE_CHECK = 60;
	private float $lastUsed;
	/** Times the storage was built anew after its connection died (for tests and diagnostics). */
	public int $reconnects = 0;

	/**
	 * @param (\Closure():IStorage)|null $reconnect builds the same storage anew, with a new
	 *                                             connection
	 */
	public function __construct(
		private IStorage $storage,
		private string $base = '',
		bool $createBase = true,
		private ?\Closure $reconnect = null,
	) {
		$this->lastUsed = microtime(true);
		$this->base = trim($base, '/');
		self::validate($this->base, true);
		$this->atomicPut = $storage->instanceOfStorage(\OCA\Files_External\Lib\Storage\AmazonS3::class);
		$this->sftpReads = $storage->instanceOfStorage(\OCA\Files_External\Lib\Storage\SFTP::class);
		if ($createBase && $this->base !== '') {
			$this->mkdirs($this->base);
		}
	}

	/** A temporary upload of put(): "<name>.part-<8 hex>". */
	public static function isTemporary(string $name): bool {
		return preg_match('/\.part-[0-9a-f]{8}$/', $name) === 1;
	}

	public function put(string $path, $stream): void {
		// not repeated on a new connection: the stream is (partly) read
		$this->idleCheck();
		$expected = self::remaining($stream);
		$target = $this->abs($path);
		$this->mkdirs(dirname($target));
		$writeTo = $this->atomicPut ? $target : $target . '.part-' . bin2hex(random_bytes(4));
		try {
			if ($this->storage->instanceOfStorage(IWriteStreamStorage::class)) {
				/** @var IWriteStreamStorage $s */
				$s = $this->storage;
				$written = $s->writeStream($writeTo, $stream);
				if ($written <= 0 && $this->storage->filesize($writeTo) !== 0) {
					throw new BackendException('Write reported no data for ' . $path);
				}
			} else {
				$out = $this->storage->fopen($writeTo, 'w');
				if ($out === false || stream_copy_to_stream($stream, $out) === false || !fflush($out)) {
					throw new BackendException('Write failed for ' . $path);
				}
				if (!fclose($out)) {
					throw new BackendException('Close failed for ' . $path);
				}
			}
			if (is_resource($stream)) {
				fclose($stream);
			}
			// Some adapters report success after a short write or a failed flush: check the size.
			if ($expected !== null && $this->storage->filesize($writeTo) !== $expected) {
				throw new BackendException('Incomplete write for ' . $path);
			}
			if ($writeTo !== $target && !$this->storage->rename($writeTo, $target)) {
				throw new BackendException('Cannot finalise ' . $path);
			}
		} catch (\Throwable $e) {
			if (is_resource($stream)) {
				fclose($stream);
			}
			if ($writeTo !== $target) {
				$this->storage->unlink($writeTo);
			}
			throw $e instanceof BackendException ? $e : new BackendException('Write failed for ' . $path . ': ' . $e->getMessage(), 0, $e);
		}
	}

	public function get(string $path) {
		return $this->call(fn () => $this->open($path));
	}

	/** @return resource */
	private function open(string $path) {
		if ($this->sftpReads) {
			$fh = SftpReadStream::open($this->storage, $this->abs($path));
			if (is_resource($fh)) {
				return $fh;
			}
			// null: not supported or failing for now; false: not opened, which need not mean
			// missing. Either way the storage's own stream decides.
		}
		$fh = $this->storage->fopen($this->abs($path), 'r');
		if ($fh === false) {
			throw new BackendException('Not found: ' . $path);
		}
		return $fh;
	}

	/** Over SFTP several at a time (SftpReadStream::readMany()); elsewhere one after the other. */
	public function getMany(array $paths): \Generator {
		foreach (array_chunk($paths, 8 * SftpReadStream::BATCH) as $chunk) {
			$fast = null;
			if ($this->sftpReads) {
				$abs = array_map(fn (string $p): string => $this->abs($p), $chunk);
				$fast = $this->call(fn () => SftpReadStream::readMany($this->storage, $abs));
				$fast = $fast === null ? null : array_combine($chunk, array_map(fn (string $a): ?string => $fast[$a] ?? null, $abs));
			}
			foreach ($chunk as $path) {
				$data = $fast[$path] ?? null;
				if ($data === null) {
					// not read in the batch (or no batches here): the usual way, which also
					// reports a missing file as such
					$fh = $this->get($path);
					try {
						$data = stream_get_contents($fh);
					} finally {
						fclose($fh);
					}
					if ($data === false) {
						throw new BackendException('Read failed for ' . $path);
					}
				}
				yield $path => $data;
			}
		}
	}

	public function getRange(string $path, int $offset, int $length): string {
		return $this->call(fn () => $this->readRange($path, $offset, $length));
	}

	private function readRange(string $path, int $offset, int $length): string {
		$fh = $this->get($path);
		try {
			if (fseek($fh, $offset) !== 0) {
				throw new BackendException('Seek failed in ' . $path);
			}
			$data = '';
			while (strlen($data) < $length && !feof($fh)) {
				$chunk = fread($fh, $length - strlen($data));
				if ($chunk === false) {
					break;
				}
				$data .= $chunk;
			}
			if (strlen($data) !== $length) {
				throw new BackendException('Short read in ' . $path);
			}
			return $data;
		} finally {
			fclose($fh);
		}
	}

	/** Whether the storage accepts the connection settings (files_external's own check). */
	public function canConnect(): bool {
		try {
			return !method_exists($this->storage, 'test') || $this->storage->test();
		} catch (\Throwable) {
			return false;
		}
	}

	public function exists(string $path): bool {
		$abs = $this->abs($path);
		return $this->call(fn () => $this->storage->file_exists($abs), fn (bool $found) => !$found);
	}

	public function list(string $prefix): array {
		$abs = $this->abs($prefix);
		$result = $this->call(function () use ($abs): array {
			$result = [];
			$this->listInto($abs, $result);
			return $result;
		}, fn (array $found) => $found === []);
		sort($result);
		$cut = $this->base === '' ? 0 : strlen($this->base) + 1;
		return array_map(fn ($p) => substr($p, $cut), $result);
	}

	public function delete(string $path): void {
		$this->idleCheck();
		$this->storage->unlink($this->abs($path));
	}

	public function move(string $from, string $to): void {
		// not repeated on a new connection: the server may have done it before the connection died
		$this->idleCheck();
		$target = $this->abs($to);
		$this->mkdirs(dirname($target));
		if (!$this->storage->rename($this->abs($from), $target)) {
			throw new BackendException("Cannot move $from to $to");
		}
	}

	public function listFiles(string $dir): array {
		$abs = $this->abs($dir);
		return $this->call(fn () => $this->filesIn($dir, $abs), fn (array $found) => $found === []);
	}

	/** @return list<string> */
	private function filesIn(string $dir, string $abs): array {
		if (!$this->storage->is_dir($abs)) {
			return []; // not created yet
		}
		$cut = $this->base === '' ? 0 : strlen($this->base) + 1;
		$names = $this->smbNames($abs);
		if ($names !== null) {
			$result = [];
			foreach ($names as $name) {
				if (!self::isTemporary($name)) {
					$result[] = substr($abs . '/' . $name, $cut);
				}
			}
			sort($result);
			return $result;
		}
		$dh = $this->storage->opendir($abs);
		if ($dh === false) {
			// An existing folder read as empty would look like a new repository.
			throw new BackendException('Cannot list ' . $dir);
		}
		$result = [];
		while (($name = readdir($dh)) !== false) {
			if ($name !== '.' && $name !== '..' && !self::isTemporary($name)) {
				$result[] = substr($abs . '/' . $name, $cut);
			}
		}
		closedir($dh);
		sort($result);
		return $result;
	}

	/**
	 * The names in an SMB folder, read in one listing; null for other storages, with access
	 * control lists on, or when the storage's internals are not as expected. The SMB storage's
	 * opendir() asks the server for the attributes of each entry to leave out hidden ones: with
	 * thousands of index files that took minutes. Hidden entries are still left out: names
	 * starting with a dot, and, after asking, any name a repository does not use (letters,
	 * digits, - and _), such as desktop.ini or Thumbs.db. The repository's own files have such
	 * names: they are not checked one by one, which was the cost.
	 *
	 * @return list<string>|null
	 */
	private function smbNames(string $abs): ?array {
		if (!$this->storage instanceof \OCA\Files_External\Lib\Storage\SMB) {
			return null;
		}
		try {
			/** @var list<string>|null */
			return (function (string $path): ?array {
				/** @psalm-suppress UndefinedThisPropertyFetch */
				if ($this->checkAcl) {
					return null;
				}
				$names = [];
				/** @psalm-suppress UndefinedThisPropertyFetch, UndefinedMethod */
				foreach ($this->share->dir(ltrim($this->buildPath($path), '/')) as $info) {
					$name = (string)$info->getName();
					/** @psalm-suppress UndefinedThisPropertyFetch */
					if (!$this->showHidden && (str_starts_with($name, '.')
						|| (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1 && $info->isHidden()))) {
						continue;
					}
					$names[] = $name;
				}
				return $names;
			})->call($this->storage, $abs);
		} catch (\Throwable) {
			return null; // another SMB storage version, or an error: the usual way reports it
		}
	}

	/**
	 * Run $op; when it throws, or $failed says its result may come from a dead connection, and
	 * the SFTP connection turns out to be dead, run it once more on a new connection.
	 *
	 * @template T
	 * @param \Closure():T $op
	 * @param (\Closure(T):bool)|null $failed
	 * @return T
	 */
	private function call(\Closure $op, ?\Closure $failed = null): mixed {
		$this->idleCheck();
		try {
			$result = $op();
		} catch (\Throwable $e) {
			if (!$this->reopenIfDead()) {
				throw $e;
			}
			return $op();
		}
		if ($failed !== null && $failed($result) && $this->reopenIfDead()) {
			return $op();
		}
		return $result;
	}

	/**
	 * A Hetzner Storage Box drops an SFTP connection that carried a lot of data and then stayed
	 * quiet for a few minutes, while phpseclib still takes it for open; files_external never
	 * builds a new one, and every request on it fails as if the file were missing. So after
	 * such a pause the connection is checked (one round trip) before it is used again.
	 */
	private function idleCheck(): void {
		$now = microtime(true);
		if ($this->reconnect !== null && $this->sftpReads && $now - $this->lastUsed >= self::IDLE_CHECK
			&& $this->alive(true) === false) {
			$this->reopen();
		}
		$this->lastUsed = microtime(true);
	}

	private function reopenIfDead(): bool {
		if ($this->reconnect === null || !$this->sftpReads || $this->alive(false) !== false) {
			return false;
		}
		$this->reopen();
		return true;
	}

	/**
	 * Whether the storage's SFTP connection is alive; null when it cannot tell.
	 * $ask: send a request instead of trusting what phpseclib saw last.
	 */
	private function alive(bool $ask): ?bool {
		try {
			if (!is_callable([$this->storage, 'getConnection'])) {
				return null;
			}
			$sftp = $this->storage->getConnection();
			if (!is_object($sftp) || !is_callable([$sftp, 'isConnected'])) {
				return null;
			}
			if (!$sftp->isConnected()) {
				return false;
			}
			if (!$ask || !is_callable([$sftp, 'stat'])) {
				return true;
			}
			if (is_callable([$sftp, 'clearStatCache'])) {
				$sftp->clearStatCache(); // or stat() answers from memory
			}
			$sftp->stat('.');
			return (bool)$sftp->isConnected();
		} catch (\Throwable) {
			return false;
		}
	}

	private function reopen(): void {
		/** @var \Closure():IStorage $reconnect */
		$reconnect = $this->reconnect;
		$this->storage = $reconnect();
		$this->reconnects++;
		$this->lastUsed = microtime(true);
		if (function_exists('OCP\Log\logger')) {
			\OCP\Log\logger('ng_backup')->info('The SFTP connection to the backup location was lost; reconnected');
		}
	}

	private function listInto(string $dir, array &$result): void {
		if (!$this->storage->is_dir($dir)) {
			return;
		}
		$dh = $this->storage->opendir($dir);
		if ($dh === false) {
			return;
		}
		while (($name = readdir($dh)) !== false) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			$child = $dir . '/' . $name;
			if ($this->storage->is_dir($child)) {
				$this->listInto($child, $result);
			} elseif (!self::isTemporary($name)) {
				$result[] = $child;
			}
		}
		closedir($dh);
	}

	/**
	 * Bytes left to read in a local $stream (php://temp, php://memory or a plain file), or null:
	 * stream wrappers of remote storages do not report a reliable size.
	 */
	public static function remaining(mixed $stream): ?int {
		if (!is_resource($stream)) {
			return null;
		}
		$meta = stream_get_meta_data($stream);
		if (!($meta['seekable'] ?? false) || !in_array($meta['wrapper_type'] ?? '', ['PHP', 'plainfile'], true)) {
			return null;
		}
		$stat = fstat($stream);
		$pos = ftell($stream);
		if ($stat === false || $pos === false) {
			return null;
		}
		return max(0, $stat['size'] - $pos);
	}

	private function mkdirs(string $dir): void {
		if ($dir === '' || $dir === '.' || $this->storage->is_dir($dir)) {
			return;
		}
		$this->mkdirs(dirname($dir) === '.' ? '' : dirname($dir));
		$this->storage->mkdir($dir);
	}

	/** Reject empty segments, '.', '..', NUL, backslashes and absolute paths. */
	private static function validate(string $path, bool $allowEmpty = false): void {
		if ($path === '' && $allowEmpty) {
			return;
		}
		if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\\") || str_contains($path, "\0")) {
			throw new BackendException('Invalid path: ' . $path);
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				throw new BackendException('Invalid path: ' . $path);
			}
		}
	}

	private function abs(string $path): string {
		self::validate(ltrim($path, '/'));
		return ltrim(($this->base === '' ? '' : $this->base . '/') . ltrim($path, '/'), '/');
	}
}
