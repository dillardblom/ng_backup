<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Job;

/**
 * Walks one or more source roots in a stable depth-first order (entries of a directory sorted
 * by name, children right after their directory), yielding "<root>/<relative path>" for files.
 *
 * The same order is used for comparing paths (compare()), so a walk can resume after a cursor
 * and be merge-joined with the previous snapshot without holding any list in memory.
 */
final class TreeWalker {
	/**
	 * @param array<string, string> $roots name => absolute directory, walked in this order
	 * @param list<string> $exclude path prefixes ("<root>/<relative>") that are skipped entirely
	 */
	public function __construct(
		private array $roots,
		private array $exclude = [],
	) {
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
		foreach ($this->exclude as $prefix) {
			if ($logical !== '' && ($logical === $prefix || str_starts_with($logical, $prefix . '/'))) {
				return;
			}
		}
		$names = @scandir($abs);
		if ($names === false) {
			return;
		}
		$names = array_values(array_diff($names, ['.', '..']));
		sort($names, SORT_STRING);
		foreach ($names as $name) {
			$childLogical = $logical === '' ? $name : $logical . '/' . $name;
			$childAbs = $abs . '/' . $name;
			if (is_link($childAbs)) {
				continue; // never follow symlinks out of the data directory
			}
			if (is_dir($childAbs)) {
				// Skip a directory entirely if everything in it sorts before the cursor.
				if ($after !== null && self::compare($childLogical, $after) < 0 && !self::isPrefix($childLogical, $after)) {
					continue;
				}
				yield from $this->walkDir($childAbs, $childLogical, $after);
			} elseif (is_file($childAbs)) {
				if ($after !== null && self::compare($childLogical, $after) <= 0) {
					continue;
				}
				$excluded = false;
				foreach ($this->exclude as $prefix) {
					if ($childLogical === $prefix) {
						$excluded = true;
						break;
					}
				}
				if (!$excluded) {
					yield $childLogical => $childAbs;
				}
			}
		}
	}

	private static function isPrefix(string $dir, string $path): bool {
		return str_starts_with($path, $dir . '/');
	}

	private static function firstSegment(string $path): string {
		return explode('/', $path, 2)[0];
	}
}
