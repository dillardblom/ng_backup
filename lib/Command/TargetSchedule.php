<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\Schedule;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TargetSchedule extends Command {
	public function __construct(
		private TargetService $targets,
		private Schedule $schedule,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:schedule')
			->setDescription('Show or set when a location is backed up')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('schedule', InputArgument::OPTIONAL, '"off", HH:MM for once a day, or HH:MM/N for every N hours from HH:MM; omit to show the current one')
			->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Time zone of the time, e.g. Europe/Amsterdam');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$t = $this->targets->get($input->getArgument('target'));
		$schedule = $input->getArgument('schedule');
		$zone = $input->getOption('timezone') ?? $this->schedule->zoneOr($t->getScheduleZone());
		if ($schedule !== null) {
			try {
				$t = $this->targets->setSchedule($t, $schedule, (string)$zone);
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
		}
		$current = (string)$t->getSchedule();
		$output->writeln($t->getName() . ': ' . ($current === '' ? 'off' : $current . ' (' . $zone . ')'));
		return 0;
	}
}
