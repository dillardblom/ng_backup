<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TargetLimit extends Command {
	public function __construct(
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:limit')
			->setDescription('Set a maximum size for the backups stored on a location (e.g. 100G), or "none"')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('size', InputArgument::OPTIONAL, 'Size like 500M, 20G, 2T, or "none"; omit to show the current limit')
			->setHelp("A backup that would exceed the limit stops with an error and administrators are notified.\n"
				. "Note: with versioning or Object Lock on the location, removed data keeps using space there\n"
				. "until it expires; keep the limit below what you are willing to pay for.");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$t = $this->targets->get($input->getArgument('target'));
		$size = $input->getArgument('size');
		if ($size !== null) {
			if (strtolower($size) === 'none') {
				$t = $this->targets->setMaxBytes($t, null);
			} elseif (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?)i?B?$/i', $size, $m)) {
				$factor = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4][strtoupper($m[2])];
				$t = $this->targets->setMaxBytes($t, (int)round((float)$m[1] * $factor));
			} else {
				$output->writeln('<error>Use a size like 500M, 20G or 2T, or "none".</error>');
				return 1;
			}
		}
		$used = $this->targets->repository($t)->storedBytes();
		$output->writeln(sprintf('%s: %.2f GiB stored, limit %s', $t->getName(), $used / 1073741824,
			$t->getMaxBytes() === null ? 'none' : sprintf('%.2f GiB', $t->getMaxBytes() / 1073741824)));
		return 0;
	}
}
