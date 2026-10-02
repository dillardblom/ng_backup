<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Trash extends Command {
	public function __construct(
		private TargetService $targets,
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:trash')
			->setDescription('List removed snapshots that can still be restored (backup:trash:restore)')
			->addArgument('target', InputArgument::OPTIONAL, 'Location name or id (default: all)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('target');
		$delay = $this->keys->deleteDelayDays() * 86400;
		$rows = [];
		foreach ($name === null ? $this->targets->list() : [$this->targets->get($name)] as $t) {
			foreach ($this->targets->repository($t)->trash() as $id => $info) {
				$rows[] = [$t->getName(), $id, (string)($info['time'] ?? ''), $info['label'], date('Y-m-d H:i', $info['forgottenAt']), $info['by'],
					date('Y-m-d H:i', $info['forgottenAt'] + $delay)];
			}
		}
		if ($rows === []) {
			$output->writeln('The trash is empty.');
			return 0;
		}
		(new Table($output))->setHeaders(['location', 'snapshot', 'made', 'label', 'removed', 'by', 'deleted after'])->setRows($rows)->render();
		return 0;
	}
}
