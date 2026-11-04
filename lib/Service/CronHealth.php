<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\Run;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Whether Nextcloud's background jobs run on a system cron, which backups need: a backup moves
 * forward only when a background job run takes its next step (#13). Without system cron, AJAX
 * cron runs a job only now and then while someone has Nextcloud open, and webcron only as often as
 * the outside service calls it.
 */
final class CronHealth {
	/** A system cron that has not run for this long has stopped (it runs every 5 minutes). */
	public const STOPPED_AFTER = 3600;

	/**
	 * A running backup that took no step for this long is waiting for background jobs. A step with
	 * system cron takes at most a few minutes (BackupJob) and the job runs every minute.
	 */
	public const WAITING_AFTER = 900;

	public function __construct(
		private IAppConfig $appConfig,
		private IURLGenerator $urls,
	) {
	}

	/**
	 * mode: ajax, webcron or cron; lastRun: the last background job run (0: never); ok: a system
	 * cron that ran recently; stopped: system cron is set but has not run for STOPPED_AFTER.
	 *
	 * @return array{mode: string, lastRun: int, ok: bool, stopped: bool, docs: string}
	 */
	public function check(?int $now = null): array {
		$now ??= time();
		$mode = $this->coreString('backgroundjobs_mode', 'ajax');
		$lastRun = (int)$this->coreString('lastcron', '0');
		$stopped = $mode === 'cron' && $now - $lastRun > self::STOPPED_AFTER;
		return [
			'mode' => $mode,
			'lastRun' => $lastRun,
			'ok' => $mode === 'cron' && !$stopped,
			'stopped' => $stopped,
			'docs' => $this->urls->linkToDocs('admin-background-jobs'),
		];
	}

	/**
	 * Seconds since a running backup took its last step, when that is long enough to say it waits
	 * for background jobs; null while it moves (or when it is not running).
	 */
	public static function waitingFor(Run $run, ?int $now = null): ?int {
		$idle = ($now ?? time()) - $run->getUpdatedAt();
		return $run->getStatus() === Run::RUNNING && $idle >= self::WAITING_AFTER ? $idle : null;
	}

	/** One line for occ and the log; null when system cron runs. */
	public static function describe(array $health, ?int $now = null): ?string {
		if ($health['ok']) {
			return null;
		}
		$last = $health['lastRun'] > 0 ? 'last ran ' . self::ago(($now ?? time()) - $health['lastRun']) : 'never ran';
		$what = $health['stopped']
			? 'Background jobs are set to system cron, but cron has not run for over an hour (' . $last . ').'
			: 'Background jobs do not run on a system cron: they are set to "' . ($health['mode'] === 'webcron' ? 'Webcron' : 'AJAX') . '" and ' . $last . '.';
		return $what . ' Backups, also the scheduled ones, need a system cron that runs every 5 minutes: ' . $health['docs'];
	}

	/** "12 min ago" style, for durations from a minute to days */
	public static function ago(int $seconds): string {
		if ($seconds < 3600) {
			return max(1, intdiv($seconds, 60)) . ' min ago';
		}
		if ($seconds < 172800) {
			return intdiv($seconds, 3600) . ' h ago';
		}
		return intdiv($seconds, 86400) . ' days ago';
	}

	/** Core stores these with different value types across versions: read whatever is there. */
	private function coreString(string $key, string $default): string {
		try {
			return $this->appConfig->getValueString('core', $key, $default);
		} catch (AppConfigTypeConflictException) {
			return (string)$this->appConfig->getValueInt('core', $key, (int)$default);
		}
	}
}
