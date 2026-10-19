<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/**
 * A backend that can read many byte ranges ahead of use, several at a time over connections of
 * its own (a full restore, where one connection at a time is bound by the round trip).
 */
interface IReadsAhead {
	/** Whether readAhead() is worth planning for: false when it would return null anyway. */
	public function canReadAhead(): bool;

	/**
	 * The ranges in the order given, each as a local stream at offset 0 holding its bytes, read a
	 * few ranges ahead of the one the caller is at. A stream is closed when the generator is
	 * resumed. Null for a range that could not be read this way (a missing object, a server or
	 * connection error): the caller reads it the usual way, which reports why.
	 *
	 * @param list<array{0:string, 1:int, 2:int}> $ranges [path, offset, length] each
	 * @param string $spool an existing local directory for the files that hold the ranges
	 * @return \Generator<int, resource|null>|null by position in $ranges; null when this backend
	 *                                             cannot read ahead now
	 */
	public function readAhead(array $ranges, string $spool): ?\Generator;
}
