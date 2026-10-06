<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use OCA\NgBackup\Service\UserRestoreService;
use OCA\NgBackup\UserMigration\UserMigrationInstaller;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RestoreUser extends Command {
	public function __construct(
		private TargetService $targets,
		private UserRestoreService $userRestore,
		private UserMigrationInstaller $installer,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:user:restore')
			->setDescription('Restore a user from a user_migration export (see backup:user:backup)')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('manifest', InputArgument::REQUIRED, 'Manifest path printed by backup:user:backup (users/<uid>/<exportId>)')
			->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'replace or as-backup', UserRestoreService::MODE_REPLACE)
			->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing account without asking (required when not interactive)')
			->addOption('install-user-migration', null, InputOption::VALUE_NONE, 'Install and enable user_migration if it is missing (non-interactive consent)')
			->setHelp(<<<'HELP'
Never imports over a live account. Modes:
  replace    (default) safety-exports the current account first, then deletes and recreates it
             fresh from the backup, under the same user id
  as-backup  restores into a new account "<uid>-bak" (or "-bak-2", ...) instead, so an admin can
             compare and transfer by hand; Nextcloud cannot rename a user id, so the backup gets
             the new name, never the existing account

Example:
  occ backup:user:restore offsite users/alice/20261005T120000Z-ab12cd34
  occ backup:user:restore offsite users/alice/20261005T120000Z-ab12cd34 --mode=as-backup
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mode = (string)$input->getOption('mode');
		$uid = explode('/', (string)$input->getArgument('manifest'))[1] ?? '';
		if ($mode === UserRestoreService::MODE_REPLACE && $uid !== '' && $this->userManager->userExists($uid) && !$input->getOption('force')) {
			// Replace deletes the live account (after a safety export). Shares to others and data of
			// apps without a user_migration migrator do not come back, so never do it unasked.
			if (!$input->isInteractive()) {
				$output->writeln("<error>$uid exists: --mode=replace deletes and recreates it. Add --force, or use --mode=as-backup.</error>");
				return 1;
			}
			$q = new ConfirmationQuestion("$uid exists and will be deleted and recreated from the backup (a safety export is made first; its shares to others and data of apps without a migrator are not restored). Continue? [y/N] ", false);
			if (!(new QuestionHelper())->ask($input, $output, $q)) {
				$output->writeln('Cancelled.');
				return 1;
			}
		}
		try {
			$output->writeln('<comment>Note: on restore, user_migration only imports the app settings on its own allowlist (e.g. calendar view and reminder settings); other app settings of this user are skipped. Reason: NG Backup uses user_migration\'s importer; widening the list would mean building a settings importer of our own.</comment>');
			$target = $this->targets->get($input->getArgument('target'));
			$this->installer->ensureEnabled($input, $output);
			$user = $this->userRestore->restoreUser($target, (string)$input->getArgument('manifest'), $mode);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Restored <info>' . $user->getUID() . '</info>.');
		return 0;
	}
}
