<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\RestoreService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Restore extends Command {
	public function __construct(
		private TargetService $targets,
		private RestoreService $restore,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:restore')
			->setDescription('Restore files or folders from a snapshot')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addArgument('path', InputArgument::REQUIRED, 'Path in the snapshot (see backup:browse)')
			->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'new-folder, merge or replace (user files)', RestoreService::MODE_NEW_FOLDER)
			->addOption('to-directory', 'd', InputOption::VALUE_REQUIRED, 'Write to this local directory instead (any path, raw files)')
			->setHelp(<<<'HELP'
User files (data/<user>/files/...) are restored through Nextcloud, so versions, trash and the
file cache stay correct. Modes:
  new-folder  (default) into "Restored <date>/<same path>" in the user's files; nothing existing is touched
  merge       into the original place; overwritten files keep their old content as a version
  replace     like merge, and files that are not in the snapshot are moved to the trash

Examples:
  occ backup:restore offsite 537bf7e9... data/alice/files/Documents
  occ backup:restore offsite 537bf7e9... data/alice/files/Documents/plan.odt --mode=merge
  occ backup:restore offsite 537bf7e9... config --to-directory=/root/restored-config
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$target = $this->targets->get($input->getArgument('target'));
			$snapshot = $input->getArgument('snapshot');
			$path = $input->getArgument('path');
			if ($dir = $input->getOption('to-directory')) {
				$n = $this->restore->restoreToDirectory($target, $snapshot, $path, $dir);
				$output->writeln("Restored <info>$n</info> files to $dir.");
				return 0;
			}
			$r = $this->restore->restoreUserFiles($target, $snapshot, $path, (string)$input->getOption('mode'),
				function (int $done, int $total, string $name) use ($output) {
					if ($output->isVerbose() || $done === $total || $done % 100 === 0) {
						$output->writeln("  $done/$total $name");
					}
				});
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf('Restored <info>%d</info> files to %s%s.', $r['restored'], $r['target'], $r['trashed'] ? ", moved {$r['trashed']} extra files to the trash" : ''));
		return 0;
	}
}
