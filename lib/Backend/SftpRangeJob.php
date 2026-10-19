<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/**
 * One range that SftpRangeFetcher reads on one connection.
 *
 * @internal
 */
final class SftpRangeJob {
	public string $handle = '';
	/** Offset of the next READ to send. */
	public int $next;
	/** @var list<array{int, int}> offset and length of parts to ask for again (after a short answer) */
	public array $redo = [];
	/** @var list<array{int, int, int, int}> request id, kind, offset and length, in the order sent */
	public array $queue = [];
	/** Bytes received. */
	public int $got = 0;
	public bool $failed = false;

	/** @var resource the spool file that holds the range */
	public $fh;

	/** @param resource $fh */
	public function __construct(
		public int $i,
		$fh,
		public int $start,
		public int $end,
	) {
		$this->fh = $fh;
		$this->next = $start;
	}
}
