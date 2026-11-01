<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListUserExports extends Command {
	public function __construct(
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:user:list')
			->setDescription('List the user exports (backup:user:backup) on a location')
			->addArgument('target', InputArgument::OPTIONAL, 'Location name or id (default: all)')
			->addArgument('user', InputArgument::OPTIONAL, 'Only the exports of this user id')
			->setHelp(<<<'HELP'
The manifest column is what occ backup:user:restore expects. A safety export made by a restore
with --mode=replace is listed here as well.

Example:
  occ backup:user:list offsite alice
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('target');
		$uid = $input->getArgument('user');
		$rows = [];
		try {
			$targets = $name === null ? $this->targets->list() : [$this->targets->get($name)];
			foreach ($targets as $t) {
				foreach ($this->targets->repository($t)->listUserExports($uid) as $path) {
					$parts = explode('/', $path, 3);
					$user = $parts[1] ?? '';
					$exportId = $parts[2] ?? '';
					$time = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', substr($exportId, 0, 16), new \DateTimeZone('UTC'));
					$rows[] = [$t->getName(), $user, $time !== false ? $time->format('Y-m-d H:i') : '', $path];
				}
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if ($rows === []) {
			$output->writeln('No user exports yet (occ backup:user:backup).');
			return 0;
		}
		(new Table($output))->setHeaders(['location', 'user', 'date (UTC)', 'manifest'])->setRows($rows)->render();
		return 0;
	}
}
