<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

/**
 * When a location is backed up: "" (off), "HH:MM" (daily) or "HH:MM/N" (from HH:MM every N hours,
 * starting over at HH:MM the next day), in the location's time zone.
 */
final class LocationSchedule {
	/** Attempts per moment after a failure, an hour apart, instead of one every few minutes. */
	public const MAX_ATTEMPTS = 3;
	public const RETRY_AFTER = 3600;

	/** @throws \InvalidArgumentException */
	public static function normalize(string $schedule): string {
		$schedule = trim($schedule);
		if ($schedule === '' || strtolower($schedule) === 'off') {
			return '';
		}
		if (preg_match('/^(([01]\d|2[0-3]):[0-5]\d)(?:\/(\d{1,2}))?$/', $schedule, $m) !== 1) {
			throw new \InvalidArgumentException('Use "off", HH:MM for once a day, or HH:MM/N for every N hours from HH:MM');
		}
		$hours = (int)($m[3] ?? 24);
		if ($hours < 1 || $hours > 24) {
			throw new \InvalidArgumentException('Every 1 to 24 hours');
		}
		return $hours === 24 ? $m[1] : $m[1] . '/' . $hours;
	}

	/**
	 * The most recent scheduled moment at or before $now; null without a schedule.
	 *
	 * @param non-empty-string $zone
	 */
	public static function lastMoment(string $schedule, string $zone, int $now): ?int {
		if ($schedule === '') {
			return null;
		}
		$tz = new \DateTimeZone($zone);
		$today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
		$moments = array_merge(self::moments($schedule, $today->modify('-1 day'), $tz), self::moments($schedule, $today, $tz));
		$last = null;
		foreach ($moments as $moment) {
			if ($moment <= $now && ($last === null || $moment > $last)) {
				$last = $moment;
			}
		}
		return $last;
	}

	/** @return list<int> the moments of one day */
	private static function moments(string $schedule, \DateTimeImmutable $day, \DateTimeZone $tz): array {
		[$time, $every] = array_pad(explode('/', $schedule), 2, '24');
		[$hour, $minute] = array_map('intval', explode(':', $time));
		$moments = [];
		for ($h = $hour; $h < 24; $h += (int)$every) {
			$moments[] = (new \DateTimeImmutable($day->format('Y-m-d') . sprintf(' %02d:%02d', $h, $minute), $tz))->getTimestamp();
		}
		return $moments;
	}

	/**
	 * Whether a backup has to start for $moment. One that was made since then is enough, also when
	 * several moments went by during a long backup. A moment from before the schedule was set does
	 * not count: choosing a time must not start a backup right away.
	 */
	public static function isDue(int $moment, int $since, ?int $lastDoneStarted, int $attempts, ?int $lastAttemptFinished, int $now): bool {
		if ($moment < $since || ($lastDoneStarted !== null && $lastDoneStarted >= $moment) || $attempts >= self::MAX_ATTEMPTS) {
			return false;
		}
		return $attempts === 0 || $now - (int)$lastAttemptFinished >= self::RETRY_AFTER;
	}
}
