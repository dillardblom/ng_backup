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

final class Prune extends Command {
	public function __construct(
		private TargetService $targets,
		private PruneService $prune,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:prune')
			->setDescription('Apply the retention policy: remove old snapshots and the data only they used')
			->addArgument('target', InputArgument::OPTIONAL, 'Location name or id (default: all that are not append-only)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be removed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('target');
		$targets = $name === null ? array_filter($this->targets->list(), fn ($t) => !$t->getAppendOnly()) : [$this->targets->get($name)];
		$dry = (bool)$input->getOption('dry-run');
		$output->writeln('Policy: ' . json_encode($this->prune->policy()->toArray()) . ($dry ? ' (dry run)' : ''));
		$rc = 0;
		foreach ($targets as $t) {
			try {
				$r = $this->prune->apply($t, $dry);
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $t->getName() . ': ' . $e->getMessage() . '</error>');
				$rc = 1;
				continue;
			}
			$p = $r['prune'];
			$output->writeln(sprintf('<info>%s</info>: keep %d, %s %d snapshots; packs: %d, %s %d, repack %d, frees %.1f MiB',
				$t->getName(), count($r['kept']), $dry ? 'would forget' : 'forgot', count($r['forgotten']), $p['packs'],
				$dry ? 'would delete' : 'deleted', $p['deleted'], $p['repacked'], $p['freedBytes'] / 1048576));
		}
		return $rc;
	}
}
