<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\RestoreFullService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RestoreFull extends Command {
	public function __construct(
		private TargetService $targets,
		private RestoreFullService $restoreFull,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:restore:full')
			->setDescription('Disaster recovery: restore the database, data directory and config on a fresh installation')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->setHelp(<<<'HELP'
For a FRESH Nextcloud installation only, same major version as the backup, right after
occ maintenance:install, occ backup:key:import and occ backup:target:add -- before anyone else
uses this instance.

Every app that was enabled when the snapshot was taken must already be installed here (its
database tables must exist before the restore can write into them); the command lists what is
missing and refuses to continue rather than guess.

Steps: the database is replaced (consistent, in one transaction); the data directory is restored
directly in place; config.php is merged in -- everything from the backup except the database
connection and data directory settings, which stay as occ maintenance:install configured them
here. Other files under config/ (if any) are not restored.

Afterwards: check trusted_domains and overwritehost/overwriteprotocol match this server, and
occ maintenance:repair.

Example:
  occ backup:restore:full offsite 537bf7e9c1a4...
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$target = $this->targets->get($input->getArgument('target'));
			$snapshot = (string)$input->getArgument('snapshot');
			$result = $this->restoreFull->restoreFull($target, $snapshot);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf(
			'Database restored (<info>%d</info> tables, <info>%d</info> unchanged), config merged (<info>%d</info> keys), <info>%d</info> data files restored.',
			count($result['dbReport']['restored']),
			count($result['dbReport']['skipped']),
			$result['configKeys'],
			$result['dataFiles'],
		));
		$output->writeln('Next: check trusted_domains and overwritehost/overwriteprotocol match this server, then occ maintenance:repair.');
		return 0;
	}
}
