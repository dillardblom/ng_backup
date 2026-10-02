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
class BackupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private BackupService $backups,
		private \OCA\NgBackup\Service\PruneService $prune,
		private \OCA\NgBackup\Service\AlertService $alerts,
		private TargetService $targets,
		private RunMapper $runs,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$budget = PHP_SAPI === 'cli' ? 240.0 : 20.0;
		$deadline = microtime(true) + $budget;
		$this->startScheduled();
		foreach ($this->runs->findRunning(null, BackupService::KIND_FULL) as $run) {
			while (microtime(true) < $deadline && $run->getStatus() === 'running') {
				$run = $this->backups->step($run, min($deadline, microtime(true) + $budget));
			}
		}
		$this->weeklyPrune();
		foreach ($this->targets->list() as $t) {
			$this->alerts->checkStale($t);
		}
	}

	/** Once a week (after Sunday's backup), apply the retention policy to non-append-only locations. */
	private function weeklyPrune(): void {
		$lastWeek = $this->appConfig->getValueString(Application::APP_ID, 'last_prune_week', '');
		if (date('N') !== '7' || $lastWeek === date('o-W') || $this->runs->findRunning(null, BackupService::KIND_FULL) !== []) {
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
		$this->appConfig->setValueString(Application::APP_ID, 'last_prune_week', date('o-W'));
	}

	/** Daily schedule: appconfig ng_backup/schedule_time "HH:MM" (server time); empty = no schedule. */
	private function startScheduled(): void {
		$at = $this->appConfig->getValueString(Application::APP_ID, 'schedule_time', '');
		if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at)) {
			return;
		}
		$todayAt = strtotime(date('Y-m-d') . ' ' . $at);
		if (time() < $todayAt) {
			return;
		}
		foreach ($this->targets->list() as $t) {
			$last = $this->runs->lastFinished($t->getId(), BackupService::KIND_FULL);
			if ($this->runs->findRunning($t->getId(), BackupService::KIND_FULL) !== [] || ($last !== null && $last->getStartedAt() >= $todayAt)) {
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
