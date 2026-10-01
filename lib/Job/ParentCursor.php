<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Job;

use OCA\NgBackup\Repository\Repository;

/**
 * Reads the previous snapshot's entries in walk order and answers "what did the parent have
 * at this path?" for increasing paths (merge-join). Only the current entry is in memory; the
 * number of entries consumed is part of the resumable state.
 */
final class ParentCursor {
	private ?\Generator $entries = null;
	private ?array $current = null;
	private int $consumed = 0;

	public function __construct(Repository $repo, ?string $snapshotId, int $skip = 0) {
		if ($snapshotId === null) {
			return;
		}
		$this->entries = $repo->entries($snapshotId);
		$this->current = $this->entries->valid() ? $this->entries->current() : null;
		while ($this->consumed < $skip && $this->current !== null) {
			$this->advance();
		}
	}

	/** @return array{p:string, s:int, m:int, b:list<string>}|null */
	public function find(string $path): ?array {
		while ($this->current !== null) {
			$c = TreeWalker::compare($this->current['p'], $path);
			if ($c === 0) {
				return $this->current;
			}
			if ($c > 0) {
				return null;
			}
			$this->advance();
		}
		return null;
	}

	public function position(): int {
		return $this->consumed;
	}

	private function advance(): void {
		$this->entries->next();
		$this->consumed++;
		$this->current = $this->entries->valid() ? $this->entries->current() : null;
	}
}
