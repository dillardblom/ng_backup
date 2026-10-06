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

final class KeyKit extends Command {
	public function __construct(
		private KeyService $keys,
		private \OCA\NgBackup\Service\AlertService $alerts,
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
		$this->alerts->securityEvent('kit_downloaded');
		$json = json_encode($this->keys->recoveryKit(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$file = $input->getOption('output');
		if ($file === null) {
			$output->write($json, false, OutputInterface::OUTPUT_RAW);
			return 0;
		}
		// Created owner-only from the start (no window where it is world-readable), never over an
		// existing file or symlink someone else may have placed at a predictable path.
		$old = umask(0077);
		try {
			$fh = @fopen($file, 'xb');
		} finally {
			umask($old);
		}
		if ($fh === false) {
			$output->writeln("<error>Cannot create $file (it must not exist yet)</error>");
			return 1;
		}
		$ok = fwrite($fh, $json) === strlen($json);
		if (!fclose($fh) || !$ok) {
			@unlink($file);
			$output->writeln("<error>Cannot write $file</error>");
			return 1;
		}
		$output->writeln("Recovery kit written to <info>$file</info>. Copy it off this server, then run <info>occ backup:key:confirm</info>.");
		return 0;
	}
}
