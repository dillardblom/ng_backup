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
use Symfony\Component\Console\Output\OutputInterface;

final class TrashRestore extends Command {
	public function __construct(
		private TargetService $targets,
		private PruneService $prune,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:trash:restore')
			->setDescription('Bring a removed snapshot back from the trash')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:trash)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->prune->untrash($this->targets->get($input->getArgument('target')), $input->getArgument('snapshot'));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Snapshot restored from the trash.');
		return 0;
	}
}
