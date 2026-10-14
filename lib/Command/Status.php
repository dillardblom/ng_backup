<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\TargetService;
use OCA\NgBackup\Service\VerifyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Status extends Command {
	public function __construct(
		private KeyService $keys,
		private TargetService $targets,
		private RunMapper $runs,
		private VerifyService $verify,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:status')->setDescription('Show the backup key, locations and recent runs');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->keys->isInitialized()) {
			$output->writeln('Key: <error>not created</error> (occ backup:key:init)');
		} else {
			$c = $this->keys->recoveryKitConfirmation();
			$output->writeln('Key: ' . $this->keys->fingerprint() . ', recovery kit '
				. ($c ? 'confirmed by ' . $c['uid'] . ' on ' . date('Y-m-d H:i', $c['time']) : '<error>not confirmed</error> (occ backup:key:confirm)'));
		}
		$names = [];
		foreach ($this->targets->list() as $t) {
			$names[$t->getId()] = $t->getName();
		}
		$output->writeln('Locations: ' . ($names === [] ? 'none' : implode(', ', $names)));
		$rows = [];
		foreach ($this->runs->findRecent(10) as $r) {
			$s = json_decode($r->getStats() ?? '{}', true)['files'] ?? [];
			$rows[] = [$r->getId(), $names[$r->getTargetId()] ?? '#' . $r->getTargetId(), $r->getKind(), $r->getStatus() . ($r->getStatus() === 'running' ? ' (' . $r->getPhase() . self::percent($r) . ')' : ''),
				date('Y-m-d H:i', $r->getStartedAt()), $r->getFinishedAt() ? gmdate('H:i:s', $r->getFinishedAt() - $r->getStartedAt()) : '',
				$s['files'] ?? '', isset($s['bytes']) ? self::size((int)$s['bytes']) : '',
				isset($s['uploaded']) ? self::size((int)$s['uploaded']) : '', mb_strimwidth((string)$r->getError(), 0, 50, '…')];
		}
		if ($rows !== []) {
			(new Table($output))->setHeaders(['run', 'location', 'kind', 'status', 'started', 'took', 'files', 'size', 'uploaded', 'error'])->setRows($rows)->render();
		}
		foreach ($this->verify->progress() as $targetId => $state) {
			$output->writeln(sprintf('Verify of %s on %s: %s', mb_strimwidth((string)($state['what'] ?? ''), 0, 40, '…'),
				$names[$targetId] ?? '#' . $targetId, VerifyService::describe($state)));
		}
		return 0;
	}

	/** " 68%", or " ~68%" when estimated from the file cache; empty without an estimate */
	private static function size(int $bytes): string {
		$b = (float)$bytes;
		foreach (['B', 'KiB', 'MiB', 'GiB'] as $u) {
			if ($b < 1024) {
				return $u === 'B' ? "$bytes B" : sprintf('%.1f %s', $b, $u);
			}
			$b /= 1024.0;
		}
		return sprintf('%.1f TiB', $b);
	}

	private static function percent(\OCA\NgBackup\Db\Run $r): string {
		$p = \OCA\NgBackup\Job\BackupRun::progressOf($r);
		return $p === null ? '' : sprintf(' %s%d%%', $p['approximate'] ? '~' : '', (int)floor($p['fraction'] * 100.0));
	}
}
