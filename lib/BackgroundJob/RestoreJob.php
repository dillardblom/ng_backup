<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\BackgroundJob;

use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Service\RestoreService;
use OCA\NgBackup\Service\TargetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/** Runs a restore requested from the web interface, outside the web request. */
class RestoreJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private RunMapper $runs,
		private TargetService $targets,
		private RestoreService $restore,
		private LoggerInterface $logger,
		private \OCA\NgBackup\Service\AlertService $alerts,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$runId = (int)($argument['run'] ?? 0);
		try {
			$run = $this->runs->find($runId);
		} catch (\Throwable $e) {
			$this->logger->error('NG Backup restore job: run ' . $runId . ' not found', ['exception' => $e]);
			return;
		}
		if ($run->getStatus() !== Run::RUNNING) {
			return;
		}
		try {
			$req = json_decode($run->getState(), true, 512, JSON_THROW_ON_ERROR);
			$run->setPhase('restoring');
			$run->setUpdatedAt(time());
			$run = $this->runs->update($run);
			@set_time_limit(0);
			$result = $this->restore->restoreUserFiles($this->targets->get((string)$run->getTargetId()), $req['snapshot'], $req['path'], $req['mode']);
			$run->setStatus(Run::DONE);
			$run->setPhase('done');
			$run->setStats(json_encode($result, JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
			$this->logger->error('NG Backup restore ' . $run->getId() . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			$run->setStatus(Run::FAILED);
			$run->setError($e->getMessage());
			try {
				$this->alerts->runFailed($run, '#' . $run->getTargetId());
			} catch (\Throwable) {
			}
		}
		$run->setFinishedAt(time());
		$run->setUpdatedAt(time());
		$this->runs->update($run);
	}
}
