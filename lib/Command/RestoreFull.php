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
		private \OCP\App\IAppManager $appManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:restore:full')
			->setDescription('Disaster recovery: restore the database, data directory and config on a fresh installation')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addOption('force', null, InputOption::VALUE_NONE, 'Also on an installation that already has more than one user, and without asking')
			->addOption('install-apps', null, InputOption::VALUE_NONE, 'First install (from the app store) or enable the apps that were enabled at backup time and are missing here')
			->addOption('skip-missing-tables', null, InputOption::VALUE_NONE, 'Older backups only: leave out tables that do not exist here and whose structure the backup does not record')
			->setHelp(<<<'HELP'
For a FRESH Nextcloud installation only, same major version as the backup, right after
occ maintenance:install, occ backup:key:import and occ backup:target:add -- before anyone else
uses this instance.

Apps: --install-apps first installs (from the app store) or enables every app that was enabled
when the snapshot was taken. Apps that are not in the app store (own builds) must be copied into
custom_apps by hand; the command lists what is still missing. Tables of apps that are missing or
were removed long ago are created from the structure recorded in the backup (backups made with
1.0.0-rc.2 or later), so they do not block the restore. Older backups do not record it: the
command then lists those tables per app, and --skip-missing-tables leaves them out.

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
			$skipMissingTables = (bool)$input->getOption('skip-missing-tables');
			if ($input->getOption('install-apps')) {
				$check = $this->restoreFull->checkCompatibility($target, $snapshot, $skipMissingTables);
				foreach ($check['missingApps'] as $app) {
					$this->installApp($app, $output);
				}
			}
			$output->writeln('Nextcloud is in maintenance mode while the restore runs (switched off again at the end, also after an error).');
			$result = $this->restoreFull->restoreFull($target, $snapshot, $skipMissingTables);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			$output->writeln('If this happened after the restore had started, the instance may be partly restored: fix the cause and run the same command again with --force.');
			$output->writeln('If occ then says that Nextcloud needs an upgrade (the database is already the old server\'s), run occ upgrade first.');
			return 1;
		}
		$output->writeln(sprintf(
			'Database restored (<info>%d</info> tables, <info>%d</info> unchanged), config merged (<info>%d</info> keys), <info>%d</info> data files restored.',
			count($result['dbReport']['restored']),
			count($result['dbReport']['skipped']),
			$result['configKeys'],
			$result['dataFiles'],
		));
		if ($result['dbReport']['created'] !== []) {
			$output->writeln(sprintf('Created <info>%d</info> tables that did not exist here (apps that are missing or were removed): %s',
				count($result['dbReport']['created']), implode(', ', $result['dbReport']['created'])));
		}
		foreach ($result['dbReport']['foreignKeyErrors'] ?? [] as $error) {
			$output->writeln('<comment>Could not add a foreign key to a created table (the data is restored): ' . $error . '</comment>');
		}
		if ($result['dbReport']['missing'] !== []) {
			$output->writeln('<comment>Left out (structure unknown): ' . implode(', ', $result['dbReport']['missing']) . '</comment>');
		}
		if ($result['missingApps'] !== []) {
			$output->writeln('<comment>These apps were enabled on the old server but are not installed here. Their data is restored; install them so they work again:</comment>');
			foreach ($result['missingApps'] as $app) {
				$output->writeln("  occ app:install $app   (not in the app store? copy it into custom_apps and run occ app:enable $app)");
			}
		}
		if (!$result['dbConsistent']) {
			$output->writeln('<comment>Warning: this snapshot\'s database dump was made over several steps (web cron), so it is not one consistent point in time. Check the instance carefully; prefer a snapshot made with system cron.</comment>');
		}
		$output->writeln('Next: check trusted_domains and overwritehost/overwriteprotocol match this server, then occ maintenance:repair.');
		return 0;
	}

	/** Enable an app that is here but switched off, or install it from the app store. */
	private function installApp(string $app, OutputInterface $output): void {
		try {
			$this->appManager->getAppPath($app);
			$command = 'app:enable'; // here, but switched off
		} catch (\OCP\App\AppPathNotFoundException) {
			$command = 'app:install';
		}
		$output->writeln("Running occ $command $app ...");
		try {
			$code = $this->getApplication()?->find($command)->run(new \Symfony\Component\Console\Input\ArrayInput(['app-id' => $command === 'app:enable' ? [$app] : $app]), $output);
		} catch (\Throwable $e) {
			$code = 1;
			$output->writeln('<comment>' . $e->getMessage() . '</comment>');
		}
		if ($code !== 0) {
			$output->writeln("<comment>Could not $command $app (not in the app store?). Copy it into custom_apps and enable it, or restore without it.</comment>");
		}
	}
}
