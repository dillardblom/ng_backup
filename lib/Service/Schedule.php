<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;

/**
 * The daily backup time: "HH:MM" in a time zone. Nextcloud runs PHP in UTC, so a time without a
 * zone meant UTC (issue #19: 03:00 ran at 05:00 in Berlin). A time saved before there was a zone
 * is converted once to the zone of the server or of an administrator, at today's offset: it then
 * shows as it effectively ran (02:30 UTC becomes 04:30 Europe/Berlin in summer).
 */
final class Schedule {
	public const TIME_KEY = 'schedule_time';
	public const ZONE_KEY = 'schedule_timezone';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IGroupManager $groups,
	) {
	}

	/** @return array{time: string, timezone: non-empty-string} time "" = no schedule */
	public function get(): array {
		$time = $this->appConfig->getValueString(Application::APP_ID, self::TIME_KEY, '');
		$zone = $this->appConfig->getValueString(Application::APP_ID, self::ZONE_KEY, '');
		if ($zone === '' || !self::validZone($zone)) {
			$zone = $this->guessZone();
			if ($time !== '' && self::validTime($time)) {
				$time = self::convert($time, 'UTC', $zone);
				$this->appConfig->setValueString(Application::APP_ID, self::TIME_KEY, $time);
			}
			$this->appConfig->setValueString(Application::APP_ID, self::ZONE_KEY, $zone);
		}
		return ['time' => $time, 'timezone' => $zone];
	}

	/** @throws \InvalidArgumentException for a malformed time or an unknown zone */
	public function set(string $time, string $zone): void {
		if ($time !== '' && !self::validTime($time)) {
			throw new \InvalidArgumentException('Use HH:MM or empty');
		}
		if (!self::validZone($zone)) {
			throw new \InvalidArgumentException('Unknown time zone: ' . $zone);
		}
		$this->appConfig->setValueString(Application::APP_ID, self::ZONE_KEY, $zone);
		$this->appConfig->setValueString(Application::APP_ID, self::TIME_KEY, $time);
	}

	/** Today's scheduled moment as a Unix time, in the schedule's zone; null without a schedule. */
	public function todayAt(?int $now = null): ?int {
		['time' => $time, 'timezone' => $zone] = $this->get();
		if ($time === '' || !self::validTime($time)) {
			return null;
		}
		$tz = new \DateTimeZone($zone);
		$day = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->format('Y-m-d');
		return (new \DateTimeImmutable($day . ' ' . $time, $tz))->getTimestamp();
	}

	/**
	 * "HH:MM" in zone $from as "HH:MM" in zone $to, at today's offsets.
	 *
	 * @param non-empty-string $from
	 * @param non-empty-string $to
	 */
	public static function convert(string $time, string $from, string $to, ?int $now = null): string {
		$day = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone($from))->format('Y-m-d');
		return (new \DateTimeImmutable($day . ' ' . $time, new \DateTimeZone($from)))->setTimezone(new \DateTimeZone($to))->format('H:i');
	}

	public static function validTime(string $time): bool {
		return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
	}

	/** @psalm-assert-if-true non-empty-string $zone */
	public static function validZone(string $zone): bool {
		return in_array($zone, \DateTimeZone::listIdentifiers(), true);
	}

	/**
	 * The server's default_timezone when set, else the first administrator's, else UTC. A server
	 * zone of UTC is passed over on purpose: it is mostly what an image or installer left there,
	 * while the administrator who set the time read it as their own (#19).
	 *
	 * @return non-empty-string
	 */
	private function guessZone(): string {
		$zone = $this->config->getSystemValueString('default_timezone', '');
		if ($zone !== '' && $zone !== 'UTC' && self::validZone($zone)) {
			return $zone;
		}
		foreach ($this->groups->get('admin')?->getUsers() ?? [] as $user) {
			$zone = $this->config->getUserValue($user->getUID(), 'core', 'timezone', '');
			if ($zone !== '' && self::validZone($zone)) {
				return $zone;
			}
		}
		return 'UTC';
	}
}
