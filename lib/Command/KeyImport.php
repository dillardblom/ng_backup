<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class KeyImport extends Command {
	public function __construct(
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:key:import')
			->setDescription('Disaster recovery: adopt the backup key from a recovery kit on a fresh installation')
			->addArgument('kit-file', InputArgument::REQUIRED, 'The recovery kit file (occ backup:key:kit on the original installation)')
			->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read the passphrase from this file instead of asking')
			->setHelp(<<<'HELP'
For a fresh installation only (occ backup:key:init must not have been run here yet). Reads one
of the kit's passphrase slots to recover the original master key, so occ backup:target:add opens
the existing repository on the location instead of creating a new, empty one.

Example:
  occ backup:key:import /root/ng_backup-recovery-kit.json
  occ backup:target:add offsite sftp -a password::password -o host=... -o user=... -o password=...
  occ backup:restore:full offsite <snapshot>
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->keys->isInitialized()) {
			$output->writeln('<error>A backup key already exists here (fingerprint ' . $this->keys->fingerprint() . '). occ backup:key:import is only for a fresh installation.</error>');
			return 1;
		}
		$kitFile = (string)$input->getArgument('kit-file');
		$raw = @file_get_contents($kitFile);
		if ($raw === false) {
			$output->writeln("<error>Cannot read $kitFile</error>");
			return 1;
		}
		try {
			$kit = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$output->writeln('<error>Not a valid recovery kit file: ' . $e->getMessage() . '</error>');
			return 1;
		}
		$file = $input->getOption('passphrase-file');
		if ($file !== null) {
			$pass = rtrim((string)@file_get_contents($file), "\r\n");
		} else {
			$helper = $this->getHelper('question');
			$q = (new Question('Passphrase of one of the kit\'s slots: '))->setHidden(true)->setHiddenFallback(false);
			$pass = (string)$helper->ask($input, $output, $q);
		}
		try {
			$this->keys->importRecoveryKit($kit, $pass);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Backup key imported, fingerprint <info>' . $this->keys->fingerprint() . '</info>.');
		$output->writeln('Next: connect the backup location with the same details as before (<info>occ backup:target:add</info>), then <info>occ backup:list</info> and <info>occ backup:restore:full</info>.');
		return 0;
	}
}
