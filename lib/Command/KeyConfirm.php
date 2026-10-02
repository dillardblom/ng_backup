<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class KeyConfirm extends Command {
	public const STATEMENT = 'I have downloaded the recovery kit and stored it safely, outside this server. '
		. 'I understand that keeping these keys safe is my own responsibility and that backups cannot be restored without them.';
	public const CONFIRMATION = 'Yes, I confirm';

	public function __construct(
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:key:confirm')
			->setDescription('Confirm that the recovery kit is stored safely (required before the first backup)')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Admin user id recorded with the confirmation', 'occ');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->keys->isInitialized()) {
			$output->writeln('<error>No backup key yet: run occ backup:key:init first.</error>');
			return 1;
		}
		$output->writeln('Key fingerprint: <info>' . $this->keys->fingerprint() . '</info>');
		$output->writeln('');
		$output->writeln(wordwrap(self::STATEMENT, 78));
		$output->writeln('');
		$code = (string)$this->getHelper('question')->ask($input, $output, new Question('Confirmation code from the recovery kit file ("confirmation_code"): '));
		if (!$this->keys->checkKitCode($code)) {
			$output->writeln('<error>That code does not match the current recovery kit (download it with occ backup:key:kit first).</error>');
			return 1;
		}
		$answer = (string)$this->getHelper('question')->ask($input, $output, new Question('Type "' . self::CONFIRMATION . '" to proceed: '));
		if (trim($answer) !== self::CONFIRMATION) {
			$output->writeln('<error>Not confirmed.</error>');
			return 1;
		}
		$this->keys->confirmRecoveryKit((string)$input->getOption('user'));
		$output->writeln('Confirmed. Backups can now run.');
		return 0;
	}
}
