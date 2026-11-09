<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\HeavyJobPause;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PauseJobs extends Command {
	public function __construct(
		private HeavyJobPause $pause,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:pause-jobs')
			->setDescription('Show or set whether heavy background jobs (Memories, Recognize, Preview Generator, ...) pause while a backup runs')
			->addArgument('setting', InputArgument::OPTIONAL, '"on" or "off"; omit to show the current one');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$setting = $input->getArgument('setting');
		if ($setting !== null) {
			if ($setting !== 'on' && $setting !== 'off') {
				$output->writeln('<error>Use "on" or "off".</error>');
				return 1;
			}
			$this->pause->setEnabled($setting === 'on');
		}
		$status = $this->pause->status();
		$output->writeln('Pausing heavy background jobs during a backup: ' . ($status['enabled'] ? 'on' : 'off'));
		if ($status['jobs'] !== []) {
			$output->writeln('Paused now: ' . implode(', ', $status['jobs']));
		}
		return 0;
	}
}
