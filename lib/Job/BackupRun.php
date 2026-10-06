<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Job;

use OCA\NgBackup\Repository\Repository;

/**
 * A file backup as a resumable state machine, for environments with short PHP time limits.
 * Each step works until its deadline, uploads what it has (pack + tree segment) and returns
 * the new state; a large file is resumed at the next blob.
 *
 * The state holds no file list: the position is a cursor in TreeWalker order, and the previous
 * snapshot is merge-joined through ParentCursor, so memory and state size stay small however
 * many files there are. The snapshot is written last; a killed step is simply redone.
 */
final class BackupRun {
	/**
	 * @param string|array<string, string> $roots one directory, or name => directory (walked in order)
	 * @param list<string> $exclude logical path prefixes to skip
	 * @param array<string, mixed> $meta extra fields for the snapshot meta record
	 */
	public static function start(string|array $roots, ?string $parent = null, string $label = '', array $exclude = [], array $meta = []): array {
		$roots = is_string($roots) ? ['' => rtrim($roots, '/')] : $roots;
		uksort($roots, fn ($a, $b) => TreeWalker::compare((string)$a, (string)$b)); // walk order = compare order
		return [
			'snapshot' => bin2hex(random_bytes(16)),
			'roots' => $roots,
			'exclude' => $exclude,
			'parent' => $parent,
			'label' => $label,
			'meta' => $meta,
			'started' => gmdate('c'),
			'phase' => 'files',
			'after' => null,
			'parentPos' => 0,
			'cur' => null,
			'segments' => [],
			'steps' => 0,
			'stats' => ['files' => 0, 'bytes' => 0, 'reused' => 0, 'read' => 0, 'newBlobs' => 0, 'dupBlobs' => 0, 'uploaded' => 0],
		];
	}

	/** Run one step until $deadline (microtime(true)); returns the new state. */
	public static function step(Repository $repo, array $state, float $deadline): array {
		$state['steps']++;
		if ($state['phase'] === 'files') {
			$stats = $state['stats'];
			$walker = new TreeWalker($state['roots'], $state['exclude'], function (string $logical) use (&$state, &$stats): void {
				self::recordUnreadable($state, $stats, $logical);
			});
			$parent = new ParentCursor($repo, $state['parent'], $state['parentPos']);
			$lines = [];
			$finishedWalk = true;

			foreach ($walker->walk($state['after']) as $logical => $path) {
				$version = Repository::fileVersion($path);
				if ($version === 'missing') {
					continue; // deleted while walking
				}
				[$size, $mtime] = array_map('intval', explode(':', $version, 3));
				$prev = $parent->find($logical);

				if ($state['cur'] === null && $prev !== null && $prev['s'] === $size && $prev['m'] === $mtime) {
					$blobs = $prev['b'];
					$stats['reused']++;
				} else {
					$cur = $state['cur'];
					if ($cur !== null && $cur['path'] === $logical && $cur['version'] !== $version) {
						// Changed since the previous step: start over, unless it keeps changing.
						if (self::countRetry($state, $stats, $logical)) {
							$cur['version'] = $version;
						} else {
							$cur = null;
						}
					}
					if ($cur === null || $cur['path'] !== $logical) {
						$cur = ['path' => $logical, 'offset' => 0, 'blobs' => [], 'version' => $version];
					}
					$fh = @fopen($path, 'rb');
					if ($fh === false) {
						self::recordUnreadable($state, $stats, $logical);
						$state['cur'] = null;
						continue;
					}
					fseek($fh, $cur['offset']);
					$complete = true;
					while (($data = self::readFull($fh, Repository::BLOB_SIZE)) !== '') {
						$cur['blobs'][] = $repo->storeData($data, $stats);
						$cur['offset'] += strlen($data);
						if (microtime(true) >= $deadline && !feof($fh)) {
							$complete = false;
							break;
						}
					}
					fclose($fh);
					$now = Repository::fileVersion($path);
					if ($now !== $cur['version']) {
						if (!self::countRetry($state, $stats, $logical)) {
							// Changed while reading: read it again from the start (now or next step).
							$state['cur'] = null;
							$finishedWalk = false;
							break;
						}
						// Keeps changing (a live database, VM image or log): keep what was read,
						// like any copy of a file in use, and report it instead of retrying forever.
						$cur['version'] = $now;
					}
					if (!$complete) {
						$state['cur'] = $cur;
						$finishedWalk = false;
						break;
					}
					$blobs = $cur['blobs'];
					$size = $cur['offset'];
					$state['cur'] = null;
					$stats['read']++;
				}

				$lines[] = json_encode(['p' => $logical, 's' => $size, 'm' => $mtime, 'b' => $blobs], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
				$stats['files']++;
				$stats['bytes'] += $size;
				$state['after'] = $logical;
				if (microtime(true) >= $deadline) {
					$finishedWalk = false;
					break;
				}
			}

			if ($lines !== []) {
				$writer = $repo->blobWriter($stats);
				$writer->write(implode("\n", $lines) . "\n");
				$state['segments'][] = $writer->finish();
			}
			$repo->flushPacks();
			$state['parentPos'] = $parent->position();
			$state['stats'] = $stats;
			if ($finishedWalk) {
				$state['phase'] = 'finish';
			}
		}

		if ($state['phase'] === 'finish') {
			$segments = $state['segments'];
			$repo->putSnapshot($state['snapshot'],
				['time' => $state['started'], 'parent' => $state['parent'], 'label' => $state['label'],
					'roots' => array_keys($state['roots']), 'stats' => $state['stats'],
					'warnings' => self::warnings($state)] + $state['meta'],
				(function () use ($repo, $segments) {
					foreach ($segments as $blobs) {
						yield from $repo->readLines($blobs);
					}
				})());
			$state['phase'] = 'commit';
		}
		return $state;
	}

	/**
	 * A file or directory that could not be read: it is missing from this snapshot. Remembered (first
	 * 1000, deduplicated across steps) so the run can report it instead of silently succeeding.
	 */
	private static function recordUnreadable(array &$state, array &$stats, string $logical): void {
		if (isset($state['unreadable'][$logical])) {
			return;
		}
		if (count($state['unreadable'] ?? []) < 1000) {
			$state['unreadable'][$logical] = true;
		}
		$stats['unreadable'] = ($stats['unreadable'] ?? 0) + 1;
	}

	/** @return array{unreadable: list<string>, changing: list<string>} examples for the report (first 50 each) */
	public static function warnings(array $state): array {
		return [
			'unreadable' => array_slice(array_map('strval', array_keys($state['unreadable'] ?? [])), 0, 50),
			'changing' => array_slice(array_map('strval', array_values($state['changing'] ?? [])), 0, 50),
		];
	}

	/** Retries of one file that changes while it is read, before its last read is kept anyway. */
	public const MAX_RETRIES = 3;

	/**
	 * Count a retry of $logical. Returns true once it has changed MAX_RETRIES times: the caller then
	 * keeps the data it read, and the file is listed in $state['changing'] (first 50) for the report.
	 */
	private static function countRetry(array &$state, array &$stats, string $logical): bool {
		$stats['retried'] = ($stats['retried'] ?? 0) + 1;
		$n = (($state['retryPath'] ?? null) === $logical ? (int)($state['retryCount'] ?? 0) : 0) + 1;
		$state['retryPath'] = $logical;
		$state['retryCount'] = $n;
		if ($n < self::MAX_RETRIES) {
			return false;
		}
		if ($n === self::MAX_RETRIES) {
			$stats['changing'] = ($stats['changing'] ?? 0) + 1;
			if (count($state['changing'] ?? []) < 50) {
				$state['changing'][] = $logical;
			}
		}
		return true;
	}

	private static function readFull($fh, int $length): string {
		$buffer = '';
		while (strlen($buffer) < $length && !feof($fh)) {
			$buffer .= (string)fread($fh, $length - strlen($buffer));
		}
		return $buffer;
	}
}
