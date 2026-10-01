<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OC\Core\Command\Base;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TargetList extends Base {
	public function __construct(
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this->setName('backup:target:list')
			->setDescription('List backup locations')
			->addOption('test', 't', InputOption::VALUE_NONE, 'Also connect to each location and check the repository');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = [];
		foreach ($this->targets->list() as $t) {
			$row = ['id' => $t->getId(), 'name' => $t->getName(), 'backend' => $t->getBackend(), 'path' => $t->getBasePath(),
				'repository' => $t->getRepositoryId(), 'added' => date('Y-m-d H:i', $t->getCreatedAt())];
			if ($input->getOption('test')) {
				try {
					$repo = $this->targets->repository($t);
					$row['status'] = 'ok, ' . count($repo->listSnapshots()) . ' snapshots';
				} catch (\Throwable $e) {
					$row['status'] = 'error: ' . $e->getMessage();
				}
			}
			$rows[] = $row;
		}
		if ($input->getOption('output') === self::OUTPUT_FORMAT_PLAIN) {
			if ($rows === []) {
				$output->writeln('No backup locations yet (occ backup:target:add).');
				return 0;
			}
			$table = new \Symfony\Component\Console\Helper\Table($output);
			$table->setHeaders(array_keys($rows[0]))->setRows(array_map('array_values', $rows))->render();
		} else {
			$this->writeMixedInOutputFormat($input, $output, $rows);
		}
		return 0;
	}
}
