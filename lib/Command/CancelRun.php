<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Service\BackupService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CancelRun extends Command {
	public function __construct(
		private RunMapper $runs,
		private BackupService $backups,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:cancel')
			->setDescription('Stop a running backup')
			->addArgument('run', InputArgument::REQUIRED, 'Run number, as shown by backup:status')
			->setHelp("No new step of the run starts; the step in progress finishes first (usually within a
"
				. "minute), so a backup that is just being completed is not cut off. The process working on the
"
				. "run (occ backup:run, or cron) then stops. A backup started by an earlier version is marked
"
				. "cancelled after two minutes and stops at the end of its step. What it already stored is
"
				. "removed by the next cleanup.");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = (string)$input->getArgument('run');
		if (!ctype_digit($id)) {
			$output->writeln('<error>Give the run number, as shown by backup:status.</error>');
			return 1;
		}
		try {
			$run = $this->runs->find((int)$id);
		} catch (DoesNotExistException) {
			$output->writeln("<error>There is no run $id.</error>");
			return 1;
		}
		if ($run->getKind() !== BackupService::KIND_FULL) {
			$output->writeln("<error>Run $id is not a backup; only backups can be cancelled.</error>");
			return 1;
		}
		if ($run->getStatus() === Run::RUNNING) {
			$output->writeln("Waiting for the current step of run $id to finish...");
		}
		try {
			$run = $this->backups->cancel($run);
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if ($run->getStatus() !== Run::CANCELLED) {
			$output->writeln("Run $id is not running (status: {$run->getStatus()}); nothing to cancel.");
			return 0;
		}
		$output->writeln("Run $id is cancelled.");
		return 0;
	}
}
