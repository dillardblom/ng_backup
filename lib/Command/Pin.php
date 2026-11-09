<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\PruneService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Pin extends Command {
	public function __construct(
		private TargetService $targets,
		private PruneService $prune,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:pin')
			->setDescription('Keep a snapshot: the retention policy does not remove a pinned one')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addOption('unpin', null, InputOption::VALUE_NONE, 'Leave the snapshot to the retention policy again');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$unpin = (bool)$input->getOption('unpin');
		try {
			$this->prune->setPinned($this->targets->get($input->getArgument('target')), $input->getArgument('snapshot'), !$unpin);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln($unpin ? 'Snapshot unpinned; the retention policy applies to it again.' : 'Snapshot pinned; the retention policy leaves it alone.');
		return 0;
	}
}
