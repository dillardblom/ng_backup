<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

/**
 * The ranges of packs a restore reads, in order, for IReadsAhead::readAhead(), and the reading of
 * blobs from them as the restore goes.
 *
 * add() takes the blobs in the order the restore needs them. A blob in the same pack as the range
 * before it, not before that range and at most GAP after its end, extends that range; anything
 * else starts a new one. read() takes the same blobs in the same order: a blob is in the current
 * range or in the next one, which then becomes current; a blob not in the plan is read elsewhere.
 */
final class ReadAheadPlan {
	/** Unused bytes between two blobs of a pack that are read along rather than asked for apart. */
	public const GAP = 4 * 1048576;
	/** Longest range: packs are 32 MiB, repacked ones too. */
	public const MAX_RANGE = 64 * 1048576;
	/** Ranges after the current one that read() looks in for a blob. */
	private const SEARCH = 16;

	/** @var list<array{0:string, 1:int, 2:int}> [path, offset, length] */
	private array $ranges = [];
	/** @var \Generator<int, resource|null>|null */
	private ?\Generator $streams = null;
	private int $current = -1;
	/** @var resource|null */
	private $stream = null;

	/** @param string $spool local folder for the ranges read ahead (see IReadsAhead::readAhead()) */
	public function __construct(
		public readonly string $spool,
	) {
	}

	public function add(string $path, int $offset, int $length): void {
		$last = array_pop($this->ranges);
		if ($last !== null) {
			[$p, $start, $len] = $last;
			if ($p === $path && $offset >= $start && $offset <= $start + $len + self::GAP
				&& $offset + $length - $start <= self::MAX_RANGE) {
				$this->ranges[] = [$p, $start, max($len, $offset + $length - $start)];
				return;
			}
			$this->ranges[] = $last;
		}
		$this->ranges[] = [$path, $offset, $length];
	}

	/** @return list<array{0:string, 1:int, 2:int}> */
	public function ranges(): array {
		return $this->ranges;
	}

	/** @param \Generator<int, resource|null> $streams what IReadsAhead::readAhead() gave for ranges() */
	public function readFrom(\Generator $streams): void {
		$this->streams = $streams;
	}

	/** The bytes of a blob, or null when its range could not be read ahead (read it the usual way). */
	public function read(string $path, int $offset, int $length): ?string {
		if ($this->streams === null) {
			return null;
		}
		if (!$this->contains($this->current, $path, $offset, $length)) {
			// Normally the next range. Further on when blobs before it were read elsewhere: the
			// index was reloaded after another server's cleanup moved them to new packs.
			$to = null;
			for ($i = $this->current + 1; $i <= $this->current + self::SEARCH && $to === null; $i++) {
				if ($this->contains($i, $path, $offset, $length)) {
					$to = $i;
				}
			}
			if ($to === null) {
				return null; // not where the plan has it
			}
			if ($this->current < 0) {
				$this->streams->current();
				$this->current = 0;
			}
			while ($this->current < $to) {
				$this->streams->next();
				$this->current++;
			}
			$stream = $this->streams->valid() ? $this->streams->current() : null;
			$this->stream = is_resource($stream) ? $stream : null;
		}
		$fh = $this->stream;
		if ($fh === null || fseek($fh, $offset - $this->ranges[$this->current][1]) !== 0) {
			return null;
		}
		$data = '';
		while (strlen($data) < $length) {
			$chunk = fread($fh, $length - strlen($data));
			if ($chunk === false || $chunk === '') {
				return null;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/** Ends the reads ahead, also halfway: closes their streams and connections. */
	public function close(): void {
		$this->stream = null;
		$streams = $this->streams;
		$this->streams = null;
		unset($streams); // the generator's finally blocks run when it is destroyed
	}

	private function contains(int $i, string $path, int $offset, int $length): bool {
		if ($i < 0 || !isset($this->ranges[$i])) {
			return false;
		}
		[$p, $start, $len] = $this->ranges[$i];
		return $p === $path && $offset >= $start && $offset + $length <= $start + $len;
	}
}
