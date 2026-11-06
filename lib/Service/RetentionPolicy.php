<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

/**
 * Which snapshots to keep: the newest $last, plus the newest snapshot of each of the most recent
 * $daily days, $weekly ISO weeks and $monthly months that have a snapshot (like restic/borg).
 */
final class RetentionPolicy {
	public function __construct(
		public readonly int $last = 3,
		public readonly int $daily = 7,
		public readonly int $weekly = 4,
		public readonly int $monthly = 6,
	) {
	}

	public static function fromArray(array $a): self {
		return new self((int)($a['last'] ?? 3), (int)($a['daily'] ?? 7), (int)($a['weekly'] ?? 4), (int)($a['monthly'] ?? 6));
	}

	public function toArray(): array {
		return ['last' => $this->last, 'daily' => $this->daily, 'weekly' => $this->weekly, 'monthly' => $this->monthly];
	}

	/**
	 * keep() for the snapshots that are not pinned, plus the pinned ones. A pinned snapshot does not
	 * take the place of a daily, weekly or monthly one.
	 *
	 * @param array<string, int> $snapshots id => unix time
	 * @param list<string> $pinned
	 * @return array<string, list<string>>
	 */
	public function keepWithPinned(array $snapshots, array $pinned): array {
		$pins = array_intersect_key($snapshots, array_flip($pinned));
		return $this->keep(array_diff_key($snapshots, $pins)) + array_fill_keys(array_keys($pins), ['pinned']);
	}

	/**
	 * @param array<string, int> $snapshots id => unix time
	 * @return array<string, list<string>> id => reasons it is kept (ids not in the result are removed)
	 */
	public function keep(array $snapshots): array {
		arsort($snapshots);
		$keep = [];
		$i = 0;
		foreach ($snapshots as $id => $time) {
			if ($i++ < $this->last) {
				$keep[$id][] = 'last';
			}
		}
		foreach (['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m'] as $kind => $format) {
			$seen = [];
			foreach ($snapshots as $id => $time) {
				$bucket = date($format, $time);
				if (isset($seen[$bucket])) {
					continue;
				}
				if (count($seen) >= $this->$kind) {
					break;
				}
				$seen[$bucket] = true;
				$keep[$id][] = $kind;
			}
		}
		return $keep;
	}
}
