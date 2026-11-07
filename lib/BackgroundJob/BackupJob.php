<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\BackgroundJob;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Service\BackupService;
use OCA\NgBackup\Service\TargetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Starts scheduled backups and moves running ones forward, a time-boxed step per cron run.
 * With system cron a step may take minutes; with webcron/AJAX cron it is kept short so it fits
 * in a normal web request (hosted Nextcloud).
 */
final class BackupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private BackupService $backups,
		private \OCA\NgBackup\Service\PruneService $prune,
		private \OCA\NgBackup\Service\AlertService $alerts,
		private TargetService $targets,
		private RunMapper $runs,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private \OCA\NgBackup\Service\Schedule $schedule,
		private \OCA\NgBackup\Service\HeavyJobPause $heavyJobs,
	) {
		parent::__construct($time);
		// Well below the usual 5-minute cron interval: the last run is stamped a moment after cron
		// starts, so with 300 the next cron run came a few seconds too early and the job ran only
		// every other time, idle for 6 of every 10 minutes during a backup. When nothing runs, the
		// job only does a few cheap database checks.
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$budget = PHP_SAPI === 'cli' ? 240.0 : 20.0;
		$deadline = microtime(true) + $budget;
		$this->startScheduled();
		$this->heavyJobs->sync($this->backups->runsInTurn() !== []);
		// One after the other: runs that read the same disks at the same time are not faster together.
		foreach ($this->backups->runsInTurn() as $run) {
			while (microtime(true) < $deadline && $run->getStatus() === 'running') {
				$next = $this->backups->step($run, min($deadline, microtime(true) + $budget));
				if ($next === $run) {
					break 2; // step() hands back the same object when another process holds the run's lease
				}
				$run = $next;
			}
		}
		$this->failStaleRestores();
		$this->weeklyPrune();
		$targets = $this->targets->list();
		foreach ($targets as $t) {
			$this->alerts->checkStale($t);
			$othersActive = array_filter($targets, static fn ($o) => $o->getId() !== $t->getId() && (string)$o->getSchedule() !== '') !== [];
			$this->alerts->checkOff($t, $othersActive);
		}
	}

	/** A restore run that has not moved for 6 hours is considered dead (it would block cleanup forever). */
	private function failStaleRestores(): void {
		foreach ($this->runs->findRunning(null, 'restore') as $run) {
			if (time() - $run->getUpdatedAt() > 6 * 3600) {
				$run->setStatus(\OCA\NgBackup\Db\Run::FAILED);
				$run->setError('No progress for 6 hours; marked as failed');
				$run->setFinishedAt(time());
				$this->runs->update($run);
				$this->alerts->runFailed($run, '#' . $run->getTargetId());
			}
		}
	}

	/**
	 * Once a week, apply the retention policy to non-append-only locations: from Sunday on, at the
	 * first moment no backup is running, so a long Sunday backup only delays it instead of skipping
	 * the week.
	 */
	private function weeklyPrune(): void {
		$lastWeek = $this->appConfig->getValueString(Application::APP_ID, 'last_prune_week', '');
		$due = date('N') === '7' ? date('o-W') : date('o-W', (int)strtotime('last sunday'));
		if (strcmp($lastWeek, $due) >= 0 || $this->runs->findRunning(null, BackupService::KIND_FULL) !== []) {
			return;
		}
		foreach ($this->targets->list() as $t) {
			if ($t->getAppendOnly()) {
				continue;
			}
			try {
				$this->prune->apply($t, false, true);
			} catch (\Throwable $e) {
				$this->logger->warning('NG Backup: cleanup of ' . $t->getName() . ' failed: ' . $e->getMessage());
			}
		}
		$this->appConfig->setValueString(Application::APP_ID, 'last_prune_week', $due);
	}

	/** Start the backups the schedules of the locations ask for; they run in turn (see LocationSchedule). */
	private function startScheduled(): void {
		$now = time();
		foreach ($this->targets->list() as $t) {
			$moment = \OCA\NgBackup\Service\LocationSchedule::lastMoment((string)$t->getSchedule(), $this->schedule->zoneOr($t->getScheduleZone()), $now);
			if ($moment === null || $this->runs->findRunning($t->getId(), BackupService::KIND_FULL) !== []) {
				continue;
			}
			$attempts = $this->runs->startedSince($t->getId(), BackupService::KIND_FULL, $moment);
			if (!\OCA\NgBackup\Service\LocationSchedule::isDue($moment, (int)$t->getScheduleSince(),
				$this->runs->lastFinished($t->getId(), BackupService::KIND_FULL)?->getStartedAt(),
				count($attempts), ($attempts[0] ?? null)?->getFinishedAt(), $now)) {
				continue;
			}
			try {
				$this->backups->start($t, 'scheduled');
			} catch (\Throwable $e) {
				$this->logger->warning('NG Backup: scheduled backup to ' . $t->getName() . ' not started: ' . $e->getMessage());
			}
		}
	}
}
