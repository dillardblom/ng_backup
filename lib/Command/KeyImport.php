<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class KeyImport extends Command {
	public function __construct(
		private KeyService $keys,
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:key:import')
			->setDescription('Disaster recovery: adopt the backup key from a recovery kit on a fresh installation')
			->addArgument('kit-file', InputArgument::REQUIRED, 'The recovery kit file (occ backup:key:kit on the original installation)')
			->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read the passphrase from this file instead of asking')
			->addOption('replace-unused-key', null, InputOption::VALUE_NONE, 'A new key was made here by mistake: replace it by the kit\'s key, if it was never used (no location, no backup)')
			->setHelp(<<<'HELP'
For a fresh installation only (occ backup:key:init must not have been run here yet). Reads one
of the kit's passphrase slots to recover the original master key, so occ backup:target:add opens
the existing repository on the location instead of creating a new, empty one.

A new key was made here by mistake before the location was added? As long as it was never used
(no location, no backup), --replace-unused-key replaces it by the kit's key. A key that was used
is never replaced.

No kit? Skip this command: occ backup:target:add then takes the key from the location with one
of the old server's passphrases. The kit still helps when the location's config file is damaged.

Example:
  occ backup:key:import /root/ng_backup-recovery-kit.json
  occ backup:target:add offsite sftp -a password::password -o host=... -o user=... -o password=...
  occ backup:restore:full offsite <snapshot>
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$replace = (bool)$input->getOption('replace-unused-key');
		if ($replace && !$this->keys->isInitialized()) {
			$output->writeln('<error>There is no backup key here to replace: leave out --replace-unused-key.</error>');
			return 1;
		}
		if ($this->keys->isInitialized()) {
			if (!$this->targets->keyIsUnused()) {
				$output->writeln('<error>A backup key already exists here (fingerprint ' . $this->keys->fingerprint() . ') and it has been used for a backup location: it is never replaced.</error>');
				$output->writeln('occ backup:key:import is for a server that is being restored.');
				return 1;
			}
			if (!$replace) {
				$output->writeln('<error>A backup key already exists here (fingerprint ' . $this->keys->fingerprint() . '), but it was never used: no location, no backup.</error>');
				$output->writeln('Made by mistake on a server that is being restored? Run this command again with --replace-unused-key:');
				$output->writeln('the kit\'s key then takes its place, and the passphrases and recovery kit of the unused key stop working.');
				return 1;
			}
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
			$raw = @file_get_contents($file);
			if ($raw === false) {
				$output->writeln('<error>Cannot read the passphrase file</error>');
				return 1;
			}
			$pass = rtrim($raw, "\r\n");
		} else {
			$helper = $this->getHelper('question');
			$q = (new Question('Passphrase of one of the kit\'s slots: '))->setHidden(true)->setHiddenFallback(false);
			$pass = (string)$helper->ask($input, $output, $q);
		}
		try {
			if ($replace) {
				$this->targets->importKitReplacingUnusedKey($kit, $pass);
			} else {
				$this->keys->importRecoveryKit($kit, $pass);
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(($replace ? 'The unused key was replaced. ' : '') . 'Backup key imported, fingerprint <info>' . $this->keys->fingerprint() . '</info>.');
		// Read from the kit file, which is not authenticated beyond the wrapped key: show it so a
		// wrong or altered value stands out.
		$output->writeln('Deletion delay for forgotten snapshots: <info>' . $this->keys->deleteDelayDays() . '</info> days (from the kit).');
		$output->writeln('Next: connect the backup location with the same details as before (<info>occ backup:target:add</info>), then <info>occ backup:list</info> and <info>occ backup:restore:full</info>.');
		return 0;
	}
}
