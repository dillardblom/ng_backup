<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use OCA\NgBackup\Service\UserBackupService;
use OCA\NgBackup\UserMigration\UserMigrationInstaller;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BackupUser extends Command {
	public function __construct(
		private TargetService $targets,
		private UserBackupService $userBackup,
		private IUserManager $userManager,
		private UserMigrationInstaller $installer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:user:backup')
			->setDescription('Back up one user via user_migration (account, settings, files and everything else a migrator covers)')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('user', InputArgument::REQUIRED, 'User id')
			->addOption('install-user-migration', null, InputOption::VALUE_NONE, 'Install and enable user_migration if it is missing (non-interactive consent)')
			->setHelp(<<<'HELP'
Streams a user_migration export straight into the encrypted repository: no zip, no copy in the
user's own storage. Shares data with the regular backup; this needs the user_migration app
enabled. The manifest path this prints is what occ backup:user:restore expects.

Example:
  occ backup:user:backup offsite alice
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('user');
		$user = $this->userManager->get($uid);
		if ($user === null) {
			$output->writeln("<error>No such user: $uid</error>");
			return 1;
		}
		try {
			$target = $this->targets->get($input->getArgument('target'));
			$this->installer->ensureEnabled($input, $output);
			$manifest = $this->userBackup->backupUser($target, $user);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln("Backed up <info>$uid</info> to $manifest.");
		return 0;
	}
}
