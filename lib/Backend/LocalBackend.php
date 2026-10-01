<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

final class LocalBackend implements IBackend {
	public function __construct(
		private string $root,
	) {
		$this->root = rtrim($root, '/');
		if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
			throw new BackendException('Cannot create ' . $this->root);
		}
	}

	public function put(string $path, $stream): void {
		$target = $this->abs($path);
		$dir = dirname($target);
		if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new BackendException('Cannot create ' . $dir);
		}
		// Write to a temporary name and rename, so a crash never leaves a partial object.
		$tmp = $target . '.part-' . bin2hex(random_bytes(4));
		$out = fopen($tmp, 'wb');
		if ($out === false) {
			throw new BackendException('Cannot write ' . $path);
		}
		try {
			if (stream_copy_to_stream($stream, $out) === false || !fflush($out)) {
				throw new BackendException('Write failed for ' . $path);
			}
		} finally {
			fclose($out);
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		if (!rename($tmp, $target)) {
			@unlink($tmp);
			throw new BackendException('Cannot finalise ' . $path);
		}
	}

	public function get(string $path) {
		$fh = @fopen($this->abs($path), 'rb');
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
				$data .= (string)fread($fh, $length - strlen($data));
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
		return is_file($this->abs($path));
	}

	public function list(string $prefix): array {
		$base = $this->abs($prefix);
		if (!is_dir($base)) {
			return [];
		}
		$result = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->isFile() && !str_contains($file->getFilename(), '.part-')) {
				$result[] = ltrim(substr($file->getPathname(), strlen($this->root)), '/');
			}
		}
		sort($result);
		return $result;
	}

	public function delete(string $path): void {
		@unlink($this->abs($path));
	}

	private function abs(string $path): string {
		if (str_contains($path, '..') || str_starts_with($path, '/')) {
			throw new BackendException('Invalid path: ' . $path);
		}
		return $this->root . '/' . $path;
	}
}
