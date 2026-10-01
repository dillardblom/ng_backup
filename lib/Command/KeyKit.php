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

class KeyKit extends Command {
	public function __construct(
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:key:kit')
			->setDescription('Write the recovery kit (passphrase-protected key) to a file or stdout')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write (default: stdout)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->keys->isInitialized()) {
			$output->writeln('<error>No backup key yet: run occ backup:key:init first.</error>');
			return 1;
		}
		$json = json_encode($this->keys->recoveryKit(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$file = $input->getOption('output');
		if ($file === null) {
			$output->write($json, false, OutputInterface::OUTPUT_RAW);
			return 0;
		}
		if (file_put_contents($file, $json) === false) {
			$output->writeln("<error>Cannot write $file</error>");
			return 1;
		}
		@chmod($file, 0600);
		$output->writeln("Recovery kit written to <info>$file</info>. Copy it off this server, then run <info>occ backup:key:confirm</info>.");
		return 0;
	}
}
