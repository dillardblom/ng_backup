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

final class Note extends Command {
	public function __construct(
		private TargetService $targets,
		private PruneService $prune,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:note')
			->setDescription('Set or remove the note on a snapshot')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addArgument('note', InputArgument::OPTIONAL, 'The note, at most ' . PruneService::NOTE_MAX . ' characters; without it the note is removed', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$note = (string)$input->getArgument('note');
		try {
			$this->prune->setNote($this->targets->get($input->getArgument('target')), $input->getArgument('snapshot'), $note);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(trim($note) === '' ? 'Note removed.' : 'Note saved.');
		return 0;
	}
}
