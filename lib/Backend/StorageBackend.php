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

	public function __construct(
		private IStorage $storage,
		private string $base = '',
	) {
		$this->base = trim($base, '/');
		self::validate($this->base, true);
		$this->atomicPut = $storage->instanceOfStorage(\OCA\Files_External\Lib\Storage\AmazonS3::class);
		if ($this->base !== '') {
			$this->mkdirs($this->base);
		}
	}

	public function put(string $path, $stream): void {
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
		$fh = $this->storage->fopen($this->abs($path), 'r');
		if ($fh === false) {
			throw new BackendException('Not found: ' . $path);
		}
		return $fh;
	}

	public function getRange(string $path, int $offset, int $length): string {
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

	public function exists(string $path): bool {
		return $this->storage->file_exists($this->abs($path));
	}

	public function list(string $prefix): array {
		$result = [];
		$this->listInto($this->abs($prefix), $result);
		sort($result);
		$cut = $this->base === '' ? 0 : strlen($this->base) + 1;
		return array_map(fn ($p) => substr($p, $cut), $result);
	}

	public function delete(string $path): void {
		$this->storage->unlink($this->abs($path));
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
			if ($name === '.' || $name === '..' || str_contains($name, '.part-')) {
				continue;
			}
			$child = $dir . '/' . $name;
			if ($this->storage->is_dir($child)) {
				$this->listInto($child, $result);
			} else {
				$result[] = $child;
			}
		}
		closedir($dh);
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
