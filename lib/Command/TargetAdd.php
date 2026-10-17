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

final class TargetAdd extends Command {
	public function __construct(
		private TargetService $targets,
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:add')
			->setDescription('Add a backup location (S3, SFTP, WebDAV, ...) via files_external, without mounting it')
			->addArgument('name', InputArgument::REQUIRED, 'Name of the location')
			->addArgument('backend', InputArgument::REQUIRED, 'files_external backend id (see backup:target:backends)')
			->addOption('auth', 'a', InputOption::VALUE_REQUIRED, 'Auth mechanism id (see backup:target:backends)')
			->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Backend/auth option key=value (repeatable)')
			->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Folder on the location for this repository', 'ng_backup')
			->setHelp(<<<'HELP'
The location is reached with files_external's own connection code, but it is never added as an
external storage: it does not appear in Files for anyone. Credentials are stored encrypted.

Examples:
  occ backup:target:add offsite amazons3 -a amazons3::accesskey       -o bucket=backups -o hostname=s3.example.com -o region=eu-central-1       -o use_ssl=true -o use_path_style=true -o key=AKIA... -o secret=... --path=nextcloud

  occ backup:target:add nas sftp -a password::password       -o host=nas.example.lan -o root=/backups -o user=nextcloud -o password=... --path=ng_backup
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->keys->isInitialized()) {
			$output->writeln('<error>No backup key yet.</error>');
			$output->writeln('Restoring from a location that already has backups (disaster recovery)? Import the key from the recovery kit first:');
			$output->writeln('  occ backup:key:import /path/to/ng_backup-kit.json');
			$output->writeln('Only for a new location without backups: create a new key with occ backup:key:init. A new key cannot read existing backups.');
			return 1;
		}
		$options = [];
		foreach ($input->getOption('option') as $pair) {
			if (!str_contains($pair, '=')) {
				$output->writeln("<error>Option '$pair' must be key=value</error>");
				return 1;
			}
			[$k, $v] = explode('=', $pair, 2);
			$options[$k] = match (strtolower($v)) { 'true' => true, 'false' => false, default => $v };
		}
		if ($input->getOption('auth') === null) {
			$output->writeln('<error>--auth is required (see occ backup:target:backends)</error>');
			return 1;
		}
		// Adopting an existing repository reads its whole index first, which takes a while on a
		// large backup or a slow line; say so instead of looking stuck.
		$output->writeln('Connecting to the location; with existing backups this reads their index first and can take a while ...');
		try {
			$r = $this->targets->add($input->getArgument('name'), $input->getArgument('backend'), $input->getOption('auth'), $options, (string)$input->getOption('path'));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf('Location <info>%s</info> added (id %d), repository %s %s.', $r['target']->getName(), $r['target']->getId(),
			$r['target']->getRepositoryId(), $r['created'] ? 'created' : 'found and adopted'));
		return 0;
	}
}
