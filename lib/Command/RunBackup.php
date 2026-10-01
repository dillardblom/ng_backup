<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Service\BackupService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunBackup extends Command {
	public function __construct(
		private BackupService $backups,
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:run')
			->setDescription('Back up this Nextcloud (database, config, data) to a location now')
			->addArgument('target', InputArgument::OPTIONAL, 'Location name or id (default: all locations)')
			->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Label for the snapshot', '')
			->addOption('background', 'b', InputOption::VALUE_NONE, 'Only start the run; background jobs finish it')
			->setHelp(<<<'HELP'
Starts a backup (or continues one that is already running for that location) and runs it to
the end in this process. Unchanged files are not read again; only new data is uploaded.

Examples:
  occ backup:run                     back up to every location
  occ backup:run offsite -l "before upgrade"
  occ backup:run offsite --background
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('target');
		try {
			$targets = $name === null ? $this->targets->list() : [$this->targets->get($name)];
		} catch (\Throwable) {
			$output->writeln('<error>No such location.</error>');
			return 1;
		}
		if ($targets === []) {
			$output->writeln('<error>No backup locations yet (occ backup:target:add).</error>');
			return 1;
		}
		$failed = 0;
		foreach ($targets as $t) {
			try {
				$run = $this->backups->start($t, (string)$input->getOption('label'));
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $t->getName() . ': ' . $e->getMessage() . '</error>');
				$failed++;
				continue;
			}
			$output->writeln(sprintf('<info>%s</info>: run %d, snapshot %s', $t->getName(), $run->getId(), $run->getSnapshotId()));
			if ($input->getOption('background')) {
				continue;
			}
			$start = microtime(true);
			$run = $this->backups->runToCompletion($run, function (Run $r) use ($output, $start) {
				$s = json_decode($r->getStats() ?? '{}', true);
				$f = $s['files'] ?? [];
				$output->writeln(sprintf('  %5.0fs  %-6s files %d (read %d, unchanged %d), uploaded %.1f MiB',
					microtime(true) - $start, $r->getPhase(), $f['files'] ?? 0, $f['read'] ?? 0, $f['reused'] ?? 0, ($f['uploaded'] ?? 0) / 1048576));
			});
			if ($run->getStatus() === Run::DONE) {
				$output->writeln(sprintf('  done in %.0fs', microtime(true) - $start));
			} else {
				$output->writeln('<error>  ' . $run->getStatus() . ': ' . $run->getError() . '</error>');
				$failed++;
			}
		}
		return $failed === 0 ? 0 : 1;
	}
}
