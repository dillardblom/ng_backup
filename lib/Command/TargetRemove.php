<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TargetRemove extends Command {
	public function __construct(
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:remove')
			->setDescription('Remove a backup location from NG Backup (the backups on it are kept)')
			->addArgument('target', InputArgument::REQUIRED, 'Name or id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$t = $this->targets->get($input->getArgument('target'));
		} catch (\Throwable) {
			$output->writeln('<error>No such location.</error>');
			return 1;
		}
		$this->targets->remove($t);
		$output->writeln('Removed <info>' . $t->getName() . '</info>. The backups on the location itself were not touched; add it again to use them.');
		return 0;
	}
}
