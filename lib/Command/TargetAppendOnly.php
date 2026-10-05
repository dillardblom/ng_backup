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

final class TargetAppendOnly extends Command {
	public function __construct(
		private TargetService $targets,
		private \OCA\NgBackup\Service\AlertService $alerts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:append-only')
			->setDescription('Make a location append-only (NG Backup never deletes there) or normal again')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('state', InputArgument::REQUIRED, 'on or off')
			->setHelp("Append-only protects backups if this server is compromised: combine it with credentials\nwithout delete rights and a lifecycle rule or object lock on the location itself.");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$state = strtolower((string)$input->getArgument('state'));
		if (!in_array($state, ['on', 'off'], true)) {
			$output->writeln('<error>state must be on or off</error>');
			return 1;
		}
		$t = $this->targets->setAppendOnly($this->targets->get($input->getArgument('target')), $state === 'on');
		if ($state === 'off') {
			$this->alerts->securityEvent('append_only_off', ['target' => $t->getName()]);
		}
		$output->writeln($t->getName() . ' is ' . ($t->getAppendOnly() ? 'append-only' : 'normal') . '.');
		return 0;
	}
}
