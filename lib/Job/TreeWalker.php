<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Job;

/**
 * Walks one or more source roots in a stable depth-first order (entries of a directory sorted
 * by name, children right after their directory), yielding "<root>/<relative path>" for files
 * and for directories with nothing in them to walk (the caller tells them apart with is_dir()).
 * A root itself without a name is never yielded.
 *
 * The same order is used for comparing paths (compare()), so a walk can resume after a cursor
 * and be merge-joined with the previous snapshot without holding any list in memory.
 */
final class TreeWalker {
	private bool $atFolder = false;

	/**
	 * @param array<string, string> $roots name => absolute directory, walked in this order
	 * @param list<string> $exclude path prefixes ("<root>/<relative>") that are skipped entirely;
	 *                              a pattern with "*" matches one path segment (fnmatch, FNM_PATHNAME)
	 */
	public function __construct(
		private array $roots,
		private array $exclude = [],
		/** Called with the logical path of a directory that cannot be listed. */
		private ?\Closure $onUnreadable = null,
		/** Also yield directories with something in them, right before the first thing in them. */
		private bool $withFolders = false,
	) {
	}

	/** Whether what was just yielded is a directory with something in it (see $withFolders). */
	public function atFolderWithContent(): bool {
		return $this->atFolder;
	}

	/** @return \Generator<string, string> logical path => absolute path, strictly after $after */
	public function walk(?string $after = null): \Generator {
		foreach ($this->roots as $name => $dir) {
			$name = (string)$name;
			if ($name !== '' && $after !== null && self::compare($name, self::firstSegment($after)) < 0) {
				continue; // whole root lies before the cursor
			}
			yield from $this->walkDir(rtrim($dir, '/'), $name, $after);
		}
	}

	/** Order used everywhere: segment by segment, byte-wise. */
	public static function compare(string $a, string $b): int {
		$sa = explode('/', $a);
		$sb = explode('/', $b);
		$n = min(count($sa), count($sb));
		for ($i = 0; $i < $n; $i++) {
			$c = strcmp($sa[$i], $sb[$i]);
			if ($c !== 0) {
				return $c < 0 ? -1 : 1;
			}
		}
		return count($sa) <=> count($sb);
	}

	private function walkDir(string $abs, string $logical, ?string $after): \Generator {
		if ($logical !== '' && $this->isExcluded($logical, true)) {
			return;
		}
		$names = @scandir($abs);
		if ($names === false) {
			if ($this->onUnreadable !== null) {
				($this->onUnreadable)($logical === '' ? '/' : $logical);
			}
			return;
		}
		$names = array_values(array_diff($names, ['.', '..']));
		sort($names, SORT_STRING);
		$empty = true; // no file or directory below it that is walked, whatever the cursor skips
		foreach ($names as $name) {
			$childLogical = $logical === '' ? $name : $logical . '/' . $name;
			$childAbs = $abs . '/' . $name;
			if (is_link($childAbs)) {
				continue; // never follow symlinks out of the data directory
			}
			$childIsDir = is_dir($childAbs);
			if (($childIsDir || is_file($childAbs)) && $empty && !$this->isExcluded($childLogical, $childIsDir)
				&& $this->withFolders && $logical !== '' && ($after === null || self::compare($logical, $after) > 0)) {
				$this->atFolder = true;
				yield $logical => $abs;
				$this->atFolder = false;
			}
			if ($childIsDir) {
				$empty = $empty && $this->isExcluded($childLogical, true);
				// Skip a directory entirely if everything in it sorts before the cursor.
				if ($after !== null && self::compare($childLogical, $after) < 0 && !self::isPrefix($childLogical, $after)) {
					continue;
				}
				yield from $this->walkDir($childAbs, $childLogical, $after);
			} elseif (is_file($childAbs)) {
				$excluded = $this->isExcluded($childLogical, false);
				$empty = $empty && $excluded;
				if ($after !== null && self::compare($childLogical, $after) <= 0) {
					continue;
				}
				if (!$excluded) {
					yield $childLogical => $childAbs;
				}
			}
		}
		if ($empty && $logical !== '' && ($after === null || self::compare($logical, $after) > 0)) {
			yield $logical => $abs;
		}
	}

	private function isExcluded(string $logical, bool $isDir): bool {
		foreach ($this->exclude as $pattern) {
			if (str_contains($pattern, '*')) {
				if (fnmatch($pattern, $logical, FNM_PATHNAME)) {
					return true;
				}
			} elseif ($logical === $pattern || ($isDir && str_starts_with($logical, $pattern . '/'))) {
				return true;
			}
		}
		return false;
	}

	private static function isPrefix(string $dir, string $path): bool {
		return str_starts_with($path, $dir . '/');
	}

	private static function firstSegment(string $path): string {
		return explode('/', $path, 2)[0];
	}
}
