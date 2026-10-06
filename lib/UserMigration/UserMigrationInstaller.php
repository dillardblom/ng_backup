<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\UserMigration;

use OCA\NgBackup\AppInfo\Application;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Installs or enables the optional user_migration app, but only when the administrator asked for it
 * (occ prompt or --install-user-migration). Runs occ in a child process, as the admin would.
 */
final class UserMigrationInstaller {
	private const APP = 'user_migration';

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function isEnabled(): bool {
		return $this->appManager->isEnabledForAnyone(self::APP);
	}

	private function codeIsPresent(): bool {
		try {
			$this->appManager->getAppPath(self::APP);
			return true;
		} catch (AppPathNotFoundException) {
			return false;
		}
	}

	/** Installs (from the app store, if missing) and enables user_migration; throws on failure. */
	public function install(): void {
		$args = $this->codeIsPresent() ? ['app:enable', self::APP] : ['app:install', self::APP];
		$occ = dirname($this->appManager->getAppPath(Application::APP_ID), 2) . '/occ';
		$command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $occ, ...$args])) . ' 2>&1';
		exec($command, $lines, $code);
		if ($code !== 0) {
			throw new \RuntimeException("occ " . implode(' ', $args) . " failed:\n" . implode("\n", $lines));
		}
	}

	/**
	 * Returns when user_migration is enabled. Otherwise asks the administrator (interactive shell) or
	 * requires --install-user-migration, and throws when neither gives consent.
	 *
	 * @throws \RuntimeException
	 */
	public function ensureEnabled(InputInterface $input, OutputInterface $output): void {
		if ($this->isEnabled()) {
			return;
		}
		$consent = $input->getOption('install-user-migration') === true;
		if (!$consent && $input->isInteractive()) {
			$consent = (new QuestionHelper())->ask($input, $output, new ConfirmationQuestion(
				'The user_migration app is not enabled. Install and enable it now? [y/N] ', false)) === true;
		}
		if (!$consent) {
			throw new \RuntimeException('The user_migration app is not enabled; per-user backup/restore needs it. Run "occ '
				. 'app:install ' . self::APP . '" and "occ app:enable ' . self::APP . '", or repeat with --install-user-migration');
		}
		$output->writeln('Installing and enabling user_migration (approved by the administrator)...');
		$this->install();
		$this->appManager->clearAppsCache();
		if (!$this->isEnabled()) {
			throw new \RuntimeException('user_migration was installed; run this command again (check with "occ app:list" if it stays disabled)');
		}
	}
}
