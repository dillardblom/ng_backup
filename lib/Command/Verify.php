<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use OCA\NgBackup\Service\VerifyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Verify extends Command {
	public function __construct(
		private TargetService $targets,
		private VerifyService $verify,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:verify')
			->setDescription('Checksum audit of a snapshot: every blob it needs must be in the (freshly re-checked) index and its pack present')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addOption('deep', null, InputOption::VALUE_NONE, 'Also download and decrypt every blob (verifies its authentication tag and content hash, not just that it is listed); much slower')
			->setHelp(<<<'HELP'
Without --deep this re-downloads and re-authenticates the index (never trusting the local cache)
and confirms every blob the snapshot needs is listed and its pack is present -- fast, no blob
content is read, so it cannot by itself catch a truncated or bit-rotted pack. With --deep every
blob is also downloaded and decrypted, which fails loudly on exactly that even if the index still
lists it -- the same verification an actual restore would do, run here deliberately instead of
waiting to find out during one.

Run this periodically (cron, picking a sample of recent snapshots) rather than only right before
you'd need to rely on a restore.

Examples:
  occ backup:verify offsite 537bf7e9c1a4...
  occ backup:verify offsite 537bf7e9c1a4... --deep
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$target = $this->targets->get($input->getArgument('target'));
			$ref = (string)$input->getArgument('snapshot');
			$deep = (bool)$input->getOption('deep');
			$result = str_starts_with($ref, 'users/')
				? $this->verify->verifyUserExport($target, $ref, $deep)
				: $this->verify->verify($target, $ref, $deep);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf('%d files, %d blobs checked%s.', $result['filesChecked'], $result['blobsChecked'],
			$deep ? sprintf(' (%.1f MiB decrypted)', $result['bytesChecked'] / 1048576) : ''));
		if ($result['missing'] !== []) {
			$output->writeln('<error>' . count($result['missing']) . ' blob(s) missing from the index:</error> ' . implode(', ', $result['missing']));
		}
		if ($result['failed'] !== []) {
			$output->writeln('<error>' . count($result['failed']) . ' blob(s) failed verification (corrupted or tampered):</error> ' . implode(', ', $result['failed']));
		}
		if ($result['unreadable'] !== []) {
			$output->writeln('<error>' . count($result['unreadable']) . ' blob(s) could not be read from the location</error> (often a temporary storage '
				. 'or network error: run the check again; if the same blobs stay unreadable, their pack may be damaged or truncated): '
				. implode(', ', $result['unreadable']));
			$output->writeln('Last read error: ' . $result['readError']);
		}
		if ($result['missing'] === [] && $result['failed'] === [] && $result['unreadable'] === []) {
			$output->writeln('<info>OK</info>');
			return 0;
		}
		return 1;
	}
}
