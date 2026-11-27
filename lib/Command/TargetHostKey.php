<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/** The pinned SSH host keys of an SFTP location (#34): show, pin, change, remove. */
final class TargetHostKey extends Command {
	public function __construct(
		private TargetService $targets,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:host-key')
			->setDescription('Show or pin the SSH host keys of an SFTP location')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->addOption('pin', null, InputOption::VALUE_NONE, 'Pin the keys the server offers now (asks first)')
			->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pin these keys instead: SHA256:... or a public key / ssh-keyscan line (repeatable)')
			->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the pinned keys: the server is no longer checked')
			->setHelp(<<<'HELP'
Nextcloud does not check an SFTP server's host key for occ and cron. With pinned keys NG Backup
does, in every context, before it sends the password or signs in: a server with another key is
refused and the backup stops with both fingerprints in the error.

Without options: the pinned keys and the keys the server offers now. Pin all of the server's
keys, not only one: which key a connection uses depends on the Nextcloud version.

Pinning keys on a location without them is only written to the audit log. Changing or removing
pinned keys also notifies all admins, so they can check that it was expected.
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$target = $this->targets->get((string)$input->getArgument('target'));
		if (!$this->targets->hasHostKeys($target)) {
			$output->writeln('<error>' . $target->getName() . ' is not an SFTP location: it has no host keys.</error>');
			return 1;
		}
		$pin = (bool)$input->getOption('pin');
		$set = $input->getOption('set');
		$remove = (bool)$input->getOption('remove');
		if ((int)$pin + (int)($set !== []) + (int)$remove > 1) {
			$output->writeln('<error>Use one of --pin, --set and --remove.</error>');
			return 1;
		}
		$pinned = $this->targets->pinnedHostKeys($target);

		if ($remove) {
			if ($pinned === []) {
				$output->writeln($target->getName() . ' has no pinned host keys.');
				return 0;
			}
			if (!$this->confirm($input, $output, 'Remove the pinned host keys of ' . $target->getName() . '? Its server is then no longer checked; all admins are notified. [y/N] ')) {
				return 1;
			}
			$this->targets->setHostKeys($target, []);
			$output->writeln('The host keys of ' . $target->getName() . ' are no longer pinned. All admins were notified.');
			return 0;
		}

		if ($set !== []) {
			try {
				$this->targets->setHostKeys($target, $set);
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			$output->writeln('Pinned for ' . $target->getName() . ': ' . implode(', ', $this->targets->pinnedHostKeys($target)) . ($pinned !== [] ? '. All admins were notified.' : '.'));
			return 0;
		}

		$output->writeln('Pinned: ' . ($pinned === [] ? 'none (any server under this name is accepted for occ and cron)' : ''));
		foreach ($pinned as $fingerprint) {
			$output->writeln('  ' . $fingerprint);
		}
		try {
			$found = $this->targets->scanHostKeys($target);
		} catch (\Throwable $e) {
			$output->writeln('<error>The server\'s host keys could not be read: ' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('The server offers now:');
		foreach ($found as $key) {
			$mark = $pinned === [] ? '' : (in_array($key['fingerprint'], $pinned, true) ? '  pinned' : '  NOT PINNED');
			$output->writeln(sprintf('  %-22s %s%s', $key['type'], $key['fingerprint'], $mark));
		}
		$offered = array_column($found, 'fingerprint');
		$matches = $pinned !== [] && array_intersect($offered, $pinned) !== [];
		if ($pinned !== [] && !$matches) {
			$output->writeln('<error>None of the server\'s keys is pinned: backups to this location are refused. If the provider changed the keys, pin the new ones; otherwise the connection may be intercepted.</error>');
		}
		if (!$pin) {
			return $pinned !== [] && !$matches ? 1 : 0;
		}
		sort($offered);
		$current = $pinned;
		sort($current);
		if ($offered === $current) {
			$output->writeln('These keys are already pinned.');
			return 0;
		}
		$output->writeln('Compare them with what the provider publishes.' . ($pinned !== [] ? ' Pinning them replaces the pinned keys and notifies all admins.' : ''));
		if (!$this->confirm($input, $output, 'Pin these host keys for ' . $target->getName() . '? [y/N] ')) {
			return 1;
		}
		$this->targets->setHostKeys($target, array_column($found, 'fingerprint'));
		$output->writeln('Pinned.' . ($pinned !== [] ? ' All admins were notified.' : ''));
		return 0;
	}

	private function confirm(InputInterface $input, OutputInterface $output, string $question): bool {
		if (!$input->isInteractive()) {
			return true; // --no-interaction: the option itself is the confirmation
		}
		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		if (!$helper->ask($input, $output, new ConfirmationQuestion($question, false))) {
			$output->writeln('Nothing was changed.');
			return false;
		}
		return true;
	}
}
