<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\RestoreService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Browse extends Command {
	public function __construct(
		private TargetService $targets,
		private RestoreService $restore,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:browse')
			->setDescription('Show folders and files in a snapshot')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot id (see backup:list)')
			->addArgument('path', InputArgument::OPTIONAL, 'Path in the snapshot, e.g. data/alice/files/Documents', '')
			->setHelp("Top level: config/ and data/. User files are under data/<user>/files/.\n\nExample:\n  occ backup:browse offsite 537bf7e9... data/alice/files");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$items = $this->restore->browse($this->targets->get($input->getArgument('target')), $input->getArgument('snapshot'), (string)$input->getArgument('path'));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if ($items === []) {
			$output->writeln('Nothing here.');
			return 0;
		}
		$rows = array_map(fn ($i) => [$i['type'] === 'dir' ? $i['name'] . '/' : $i['name'], $this->size($i['size']),
			$i['type'] === 'dir' ? $i['files'] . ' files' : date('Y-m-d H:i', (int)$i['mtime'])], $items);
		(new Table($output))->setHeaders(['name', 'size', 'files / modified'])->setRows($rows)->render();
		return 0;
	}

	private function size(int $b): string {
		foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $u) {
			if ($b < 1024 || $u === 'TiB') {
				return $u === 'B' ? "$b B" : sprintf('%.1f %s', $b, $u);
			}
			$b /= 1024;
		}
		return '';
	}
}
