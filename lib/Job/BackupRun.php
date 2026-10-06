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
			$walker = new TreeWalker($state['roots'], $state['exclude']);
			$parent = new ParentCursor($repo, $state['parent'], $state['parentPos']);
			$stats = $state['stats'];
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
					if ($cur === null || $cur['path'] !== $logical || $cur['version'] !== $version) {
						if ($cur !== null && $cur['path'] === $logical) {
							$stats['retried'] = ($stats['retried'] ?? 0) + 1; // changed since the previous step
						}
						$cur = ['path' => $logical, 'offset' => 0, 'blobs' => [], 'version' => $version];
					}
					$fh = fopen($path, 'rb');
					if ($fh === false) {
						$stats['unreadable'] = ($stats['unreadable'] ?? 0) + 1;
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
					if (Repository::fileVersion($path) !== $cur['version']) {
						// Changed while reading: read it again from the start (now or next step).
						$stats['retried'] = ($stats['retried'] ?? 0) + 1;
						$state['cur'] = null;
						$finishedWalk = false;
						break;
					}
					if (!$complete) {
						$state['cur'] = $cur;
						$finishedWalk = false;
						break;
					}
					$blobs = $cur['blobs'];
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
					'roots' => array_keys($state['roots']), 'stats' => $state['stats']] + $state['meta'],
				(function () use ($repo, $segments) {
					foreach ($segments as $blobs) {
						yield from $repo->readLines($blobs);
					}
				})());
			$state['phase'] = 'commit';
		}
		return $state;
	}

	private static function readFull($fh, int $length): string {
		$buffer = '';
		while (strlen($buffer) < $length && !feof($fh)) {
			$buffer .= (string)fread($fh, $length - strlen($buffer));
		}
		return $buffer;
	}
}
