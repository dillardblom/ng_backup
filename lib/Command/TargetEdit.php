<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\AlertService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TargetEdit extends Command {
	public function __construct(
		private TargetService $targets,
		private AlertService $alerts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:edit')
			->setDescription('Change the connection settings or the name of a backup location, keeping its backups')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'New name')
			->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'New files_external backend id (see backup:target:backends)')
			->addOption('auth', 'a', InputOption::VALUE_REQUIRED, 'New auth mechanism id')
			->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Option key=value to change (repeatable); other options keep their value')
			->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'New folder on the location')
			->setHelp(<<<'HELP'
The new settings must lead to the same repository as before, so the history of the location is
kept. Use it after a password change, a new host name, or after moving the repository.

Example:
  occ backup:target:edit nas -o password=new-secret
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$t = $this->targets->get($input->getArgument('target'));
			$options = $this->targets->options($t);
			if ($input->getOption('backend') !== null || $input->getOption('auth') !== null) {
				$options = []; // a different connection type has its own options
			}
			foreach ($input->getOption('option') as $pair) {
				if (!str_contains($pair, '=')) {
					$output->writeln("<error>Option '$pair' must be key=value</error>");
					return 1;
				}
				$k = (string)strstr($pair, '=', true);
				$v = substr($pair, strlen($k) + 1);
				$options[$k] = match (strtolower($v)) { 'true' => true, 'false' => false, default => $v };
			}
			$before = $t->getName();
			$t = $this->targets->update($t, $input->getOption('name') ?? $t->getName(), $input->getOption('backend') ?? $t->getBackend(),
				$input->getOption('auth') ?? $t->getAuth(), $options, $input->getOption('path') ?? $t->getBasePath());
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$this->alerts->securityEvent('target_changed', ['target' => $before]);
		$output->writeln(sprintf('Location <info>%s</info> updated; repository %s found with the new settings.', $t->getName(), (string)$t->getRepositoryId()));
		return 0;
	}
}
