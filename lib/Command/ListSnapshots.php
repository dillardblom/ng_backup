<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Db\SnapshotMapper;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListSnapshots extends Command {
	public function __construct(
		private TargetService $targets,
		private SnapshotMapper $snapshots,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:list')
			->setDescription('List the snapshots (restore points) on a location')
			->addArgument('target', InputArgument::OPTIONAL, 'Location name or id (default: all)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$name = $input->getArgument('target');
		$targets = $name === null ? $this->targets->list() : [$this->targets->get($name)];
		$rows = [];
		foreach ($targets as $t) {
			foreach ($this->snapshots->findForTarget($t->getId()) as $s) {
				$rows[] = [$t->getName(), $s->getSnapshotId(), date('Y-m-d H:i', $s->getCreatedAt()), $s->getKind(),
					$s->getFiles(), sprintf('%.1f GiB', ($s->getBytes() ?? 0) / 1073741824), $s->getLabel() ?? ''];
			}
		}
		if ($rows === []) {
			$output->writeln('No snapshots yet (occ backup:run).');
			return 0;
		}
		(new Table($output))->setHeaders(['location', 'snapshot', 'date', 'kind', 'files', 'size', 'label'])->setRows($rows)->render();
		return 0;
	}
}
