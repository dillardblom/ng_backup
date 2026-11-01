<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup;

final class Format {
	/** "12:10:13", or "1d 12:10:13" from a day on (a long first backup takes days). */
	public static function duration(int $seconds): string {
		$seconds = max(0, $seconds);
		$days = intdiv($seconds, 86400);
		$time = gmdate('H:i:s', $seconds % 86400);
		return $days > 0 ? $days . 'd ' . $time : $time;
	}

	/**
	 * "2026-10-29 04:30" in $zone. Nextcloud runs PHP in UTC; occ shows times in the zone of the
	 * schedule, the administrator's, as the web interface does.
	 *
	 * @param non-empty-string $zone
	 */
	public static function time(int $timestamp, string $zone): string {
		return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i');
	}
}
