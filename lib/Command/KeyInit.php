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

class KeyInit extends Command {
	public function __construct(
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:key:init')
			->setDescription('Create the backup key, protected by a passphrase you keep safe')
			->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read the passphrase from this file instead of asking')
			->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Label of the first passphrase slot (who/where), e.g. "Safe"', 'Slot 1')
			->addOption('delete-delay-days', null, InputOption::VALUE_REQUIRED, 'Days a removed snapshot stays in the trash before its data is deleted (1-365, fixed after this)', (string)KeyService::DEFAULT_DELETE_DELAY)
			->setHelp(<<<'HELP'
Creates the master key that encrypts all backups of this installation.

The server keeps the key (encrypted with this instance's secret) so scheduled backups can run.
A copy protected by your passphrase goes into every backup location and into the recovery kit
(occ backup:key:kit). To restore on a new server you need the recovery kit and the passphrase.

Example:
  occ backup:key:init
  occ backup:key:kit --output=/root/ng_backup-recovery-kit.json
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->keys->isInitialized()) {
			$output->writeln('<error>A backup key already exists (fingerprint ' . $this->keys->fingerprint() . ').</error>');
			return 1;
		}
		$file = $input->getOption('passphrase-file');
		if ($file !== null) {
			$pass = rtrim((string)@file_get_contents($file), "\r\n");
		} else {
			$helper = $this->getHelper('question');
			$q = (new Question('Passphrase (min. 12 characters): '))->setHidden(true)->setHiddenFallback(false);
			$pass = (string)$helper->ask($input, $output, $q);
			$again = (string)$helper->ask($input, $output, (new Question('Repeat passphrase: '))->setHidden(true)->setHiddenFallback(false));
			if ($pass !== $again) {
				$output->writeln('<error>The passphrases do not match.</error>');
				return 1;
			}
		}
		try {
			$this->keys->initialize($pass, (int)$input->getOption('delete-delay-days'), (string)$input->getOption('label'));
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Backup key created, fingerprint <info>' . $this->keys->fingerprint() . '</info>.');
		$output->writeln('Removed snapshots stay in the trash on the location for <info>' . $this->keys->deleteDelayDays() . ' days</info> (cannot be changed later).');
		$output->writeln('Tip: add up to two more passphrases for other people with occ backup:key:slot:add (e.g. CTO, head of IT).');
		$output->writeln('Next: save the recovery kit with <info>occ backup:key:kit --output=FILE</info> and confirm with <info>occ backup:key:confirm</info>.');
		return 0;
	}
}
