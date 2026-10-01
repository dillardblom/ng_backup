<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Job;

use OCA\NgBackup\Repository\Repository;

/**
 * A backup run as a resumable state machine, for environments with short PHP time limits
 * (webcron/AJAX cron on hosted Nextcloud). Each step works until its deadline, then uploads
 * what it has (pack + tree segment) and returns the new state. Even a single large file is
 * resumed at the blob where the previous step stopped.
 *
 * The state is a plain array (in the app: a database row). It is only saved after a step
 * completes; if a process is killed mid-step, the next step redoes that work. Blobs uploaded by
 * the killed step are not referenced and are removed later by pruning; nothing is corrupted,
 * because the snapshot is written last.
 */
final class BackupRun {
	public static function start(string $source, ?string $parent = null, string $label = ''): array {
		return [
			'snapshot' => bin2hex(random_bytes(16)),
			'source' => rtrim($source, '/'),
			'parent' => $parent,
			'label' => $label,
			'started' => gmdate('c'),
			'phase' => 'scan',
			'files' => [],
			'pos' => 0,
			'cur' => null,
			'segments' => [],
			'steps' => 0,
			'stats' => ['files' => 0, 'reused' => 0, 'read' => 0, 'newBlobs' => 0, 'dupBlobs' => 0, 'uploaded' => 0],
		];
	}

	/** Run one step until $deadline (microtime(true)); returns the new state. */
	public static function step(Repository $repo, array $state, float $deadline): array {
		$state['steps']++;
		if ($state['phase'] === 'scan') {
			$state['files'] = self::scan($state['source']);
			$state['phase'] = 'files';
			// Scanning can take a while on big trees; continue in the same step if time is left.
		}

		if ($state['phase'] === 'files') {
			$previous = $state['parent'] !== null ? $repo->snapshotEntries($state['parent']) : [];
			$stats = $state['stats'];
			$lines = [];
			$total = count($state['files']);
			while ($state['pos'] < $total) {
				$relative = $state['files'][$state['pos']];
				$path = $state['source'] . '/' . $relative;
				clearstatcache(true, $path);
				$size = @filesize($path);
				$mtime = @filemtime($path);
				if ($size === false) {
					// Deleted since the scan: skip.
					$state['pos']++;
					$state['cur'] = null;
					continue;
				}
				$prev = $previous[$relative] ?? null;
				if ($state['cur'] === null && $prev !== null && $prev['s'] === $size && $prev['m'] === $mtime) {
					$lines[] = self::line($relative, $size, $mtime, $prev['b']);
					$stats['files']++;
					$stats['reused']++;
					$state['pos']++;
					continue;
				}

				$version = Repository::fileVersion($path);
				$cur = $state['cur'];
				if ($cur === null || $cur['version'] !== $version) {
					// New file, or it changed since the previous step: (re)start reading it.
					if ($cur !== null) {
						$stats['retried'] = ($stats['retried'] ?? 0) + 1;
					}
					$cur = ['path' => $relative, 'offset' => 0, 'blobs' => [], 'version' => $version];
				}
				$fh = fopen($path, 'rb');
				fseek($fh, $cur['offset']);
				$finished = true;
				while (($data = self::readFull($fh, Repository::BLOB_SIZE)) !== '') {
					$cur['blobs'][] = $repo->storeData($data, $stats);
					$cur['offset'] += strlen($data);
					if (microtime(true) >= $deadline && !feof($fh)) {
						$finished = false;
						break;
					}
				}
				fclose($fh);
				if (Repository::fileVersion($path) !== $cur['version']) {
					// Changed while reading: start this file again (in this or the next step).
					$stats['retried'] = ($stats['retried'] ?? 0) + 1;
					$state['cur'] = null;
					if (microtime(true) >= $deadline) {
						break;
					}
					continue;
				}
				if (!$finished) {
					$state['cur'] = $cur;
					break;
				}
				$lines[] = self::line($relative, $size, $mtime, $cur['blobs']);
				$stats['files']++;
				$stats['read']++;
				$state['cur'] = null;
				$state['pos']++;
				if (microtime(true) >= $deadline) {
					break;
				}
			}

			// Persist this step's tree lines as a blob stream segment and upload the open pack.
			if ($lines !== []) {
				$writer = $repo->blobWriter($stats);
				$writer->write(implode("\n", $lines) . "\n");
				$state['segments'][] = $writer->finish();
			}
			$repo->flushPacks();
			$state['stats'] = $stats;
			if ($state['pos'] >= $total) {
				$state['phase'] = 'finish';
			}
		}

		if ($state['phase'] === 'finish' && microtime(true) < $deadline + 5) {
			$segments = $state['segments'];
			$repo->writeSnapshot($state['snapshot'],
				['time' => $state['started'], 'parent' => $state['parent'], 'label' => $state['label'], 'source' => $state['source']],
				(function () use ($repo, $segments) {
					foreach ($segments as $blobs) {
						yield from $repo->readLines($blobs);
					}
				})());
			$state['phase'] = 'done';
			$state['files'] = [];
		}
		return $state;
	}

	/** @return list<string> */
	private static function scan(string $root): array {
		$files = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->isFile()) {
				$files[] = substr($file->getPathname(), strlen($root) + 1);
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	private static function line(string $path, int $size, int $mtime, array $blobs): string {
		return json_encode(['p' => $path, 's' => $size, 'm' => $mtime, 'b' => $blobs], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	private static function readFull($fh, int $length): string {
		$buffer = '';
		while (strlen($buffer) < $length && !feof($fh)) {
			$buffer .= (string)fread($fh, $length - strlen($buffer));
		}
		return $buffer;
	}
}
