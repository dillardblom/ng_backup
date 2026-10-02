<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\KeySlotService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/** backup:key:slots, backup:key:slot:add, backup:key:slot:replace, backup:key:slot:remove */
class KeySlots extends Command {
	public function __construct(
		private KeyService $keys,
		private KeySlotService $slots,
		private string $action = 'list',
	) {
		// list is the default; subclasses pick another action
		parent::__construct();
	}

	protected function configure(): void {
		$help = <<<'HELP'
Up to three passphrases ("slots") can open the backup key, so that one person leaving or dying
does not lock you out. Example: one passphrase in the safe, one with the CTO, one with the head
of IT. Each holder keeps a copy of the same recovery kit and only knows their own passphrase.
After every change: occ backup:key:kit and occ backup:key:confirm.
HELP;
		match ($this->action) {
			'add' => $this->setName('backup:key:slot:add')->setDescription('Add a passphrase slot')
				->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Who or where, e.g. "CTO" or "Safe"', 'Slot'),
			'replace' => $this->setName('backup:key:slot:replace')->setDescription('Set a new passphrase for a slot (e.g. its holder left)')
				->addArgument('slot', InputArgument::REQUIRED, 'Slot number (see backup:key:slots)')
				->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'New label'),
			'remove' => $this->setName('backup:key:slot:remove')->setDescription('Remove a passphrase slot (not the last one)')
				->addArgument('slot', InputArgument::REQUIRED, 'Slot number (see backup:key:slots)'),
			default => $this->setName('backup:key:slots')->setDescription('List the passphrase slots of the backup key'),
		};
		if ($this->action === 'add' || $this->action === 'replace') {
			$this->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read the passphrase from this file');
		}
		$this->setHelp($help);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->keys->isInitialized()) {
			$output->writeln('<error>No backup key yet: run occ backup:key:init first.</error>');
			return 1;
		}
		try {
			switch ($this->action) {
				case 'add':
					$n = $this->slots->add($this->passphrase($input, $output), (string)$input->getOption('label'));
					$output->writeln("Slot $n added.");
					break;
				case 'replace':
					$this->slots->replace((int)$input->getArgument('slot'), $this->passphrase($input, $output), $input->getOption('label'));
					$output->writeln('Slot ' . (int)$input->getArgument('slot') . ' has a new passphrase.');
					break;
				case 'remove':
					$this->slots->remove((int)$input->getArgument('slot'));
					$output->writeln('Slot ' . (int)$input->getArgument('slot') . ' removed.');
					break;
				default:
					(new Table($output))->setHeaders(['slot', 'label', 'set on'])
						->setRows(array_map(fn ($s) => [$s['slot'], $s['label'], $s['created'] ? date('Y-m-d H:i', $s['created']) : ''], $this->keys->slots()))->render();
					return 0;
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Now download and confirm the new recovery kit: occ backup:key:kit -o FILE && occ backup:key:confirm');
		return 0;
	}

	private function passphrase(InputInterface $input, OutputInterface $output): string {
		if ($file = $input->getOption('passphrase-file')) {
			return rtrim((string)@file_get_contents($file), "\r\n");
		}
		$h = $this->getHelper('question');
		$p = (string)$h->ask($input, $output, (new Question('Passphrase (min. 12 characters): '))->setHidden(true)->setHiddenFallback(false));
		if ($p !== (string)$h->ask($input, $output, (new Question('Repeat: '))->setHidden(true)->setHiddenFallback(false))) {
			throw new \InvalidArgumentException('The passphrases do not match');
		}
		return $p;
	}
}
