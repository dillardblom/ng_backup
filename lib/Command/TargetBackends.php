<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OC\Core\Command\Base;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TargetBackends extends Base {
	public function __construct(
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this->setName('backup:target:backends')
			->setDescription('List the storage backends (from files_external) that can be used as backup location');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$backends = $this->targets->availableBackends();
		if ($input->getOption('output') !== self::OUTPUT_FORMAT_PLAIN) {
			$this->writeMixedInOutputFormat($input, $output, $backends);
			return 0;
		}
		foreach ($backends as $b) {
			$output->writeln(sprintf('<info>%s</info> (%s)', $b['id'], $b['name']));
			foreach ($b['parameters'] as $key => $p) {
				$output->writeln(sprintf('    -o %s=...   %s', $key, is_array($p) ? ($p['value'] ?? '') : ''));
			}
			foreach ($b['auth'] as $a) {
				$output->writeln(sprintf('  -a %s  (%s)', $a['id'], $a['name']));
				foreach ($a['parameters'] as $key => $p) {
					$output->writeln(sprintf('      -o %s=...   %s', $key, is_array($p) ? ($p['value'] ?? '') : ''));
				}
			}
		}
		return 0;
	}
}
