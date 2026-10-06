<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\RestoreFullService;
use OCA\NgBackup\Service\TargetService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RestoreFull extends Command {
	public function __construct(
		private TargetService $targets,
		private RestoreFullService $restoreFull,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:restore:full')
			->setDescription('Disaster recovery: restore the database, data directory and config on a fresh installation')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addOption('force', null, InputOption::VALUE_NONE, 'Also on an installation that already has more than one user, and without asking')
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
		$force = (bool)$input->getOption('force');
		// A whole-server restore replaces the database and data directory of THIS instance. On
		// anything but a fresh install (only the admin from occ maintenance:install) that is
		// almost certainly a mistake, so it needs --force.
		$users = $this->userManager->countUsersTotal(2);
		if (!$force && ($users === false || $users > 1)) {
			$output->writeln('<error>This installation already has more than one user: backup:restore:full would replace its whole database and data directory. It is meant for a fresh installation. Use --force if this really is what you want.</error>');
			return 1;
		}
		if (!$force && $input->isInteractive()) {
			$q = new ConfirmationQuestion('This replaces the database, the data directory and config.php of this installation. Continue? [y/N] ', false);
			if (!(new QuestionHelper())->ask($input, $output, $q)) {
				$output->writeln('Cancelled.');
				return 1;
			}
		}
		try {
			$target = $this->targets->get($input->getArgument('target'));
			$snapshot = (string)$input->getArgument('snapshot');
			$result = $this->restoreFull->restoreFull($target, $snapshot);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('If this happened after the restore had started, the instance may be partly restored: fix the cause and run the same command again with --force.');
			return 1;
		}
		$output->writeln(sprintf(
			'Database restored (<info>%d</info> tables, <info>%d</info> unchanged), config merged (<info>%d</info> keys), <info>%d</info> data files restored.',
			count($result['dbReport']['restored']),
			count($result['dbReport']['skipped']),
			$result['configKeys'],
			$result['dataFiles'],
		));
		if (!$result['dbConsistent']) {
			$output->writeln('<comment>Warning: this snapshot\'s database dump was made over several steps (web cron), so it is not one consistent point in time. Check the instance carefully; prefer a snapshot made with system cron.</comment>');
		}
		$output->writeln('Next: check trusted_domains and overwritehost/overwriteprotocol match this server, then occ maintenance:repair.');
		return 0;
	}
}
