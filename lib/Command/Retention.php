<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\PruneService;
use OCA\NgBackup\Service\RetentionPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Retention extends Command {
	public function __construct(
		private PruneService $prune,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:retention')
			->setDescription('Show or set how many snapshots are kept')
			->addOption('last', null, InputOption::VALUE_REQUIRED, 'Always keep the newest N')
			->addOption('daily', null, InputOption::VALUE_REQUIRED, 'Keep one per day for the last N days with a backup')
			->addOption('weekly', null, InputOption::VALUE_REQUIRED, 'Keep one per week for N weeks')
			->addOption('monthly', null, InputOption::VALUE_REQUIRED, 'Keep one per month for N months')
			->setHelp("Example: occ backup:retention --last=3 --daily=7 --weekly=4 --monthly=12");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$p = $this->prune->policy()->toArray();
		$changed = false;
		foreach (['last', 'daily', 'weekly', 'monthly'] as $k) {
			if ($input->getOption($k) !== null) {
				$p[$k] = max(0, (int)$input->getOption($k));
				$changed = true;
			}
		}
		if ($changed) {
			try {
				$this->prune->setPolicy(RetentionPolicy::fromArray($p));
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
		}
		$output->writeln(sprintf('Keep the last %d, plus one per day for %d days, per week for %d weeks, per month for %d months.', $p['last'], $p['daily'], $p['weekly'], $p['monthly']));
		return 0;
	}
}
