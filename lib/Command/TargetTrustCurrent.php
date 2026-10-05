<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Db\AppConfigCatalogAnchor;
use OCA\NgBackup\Service\AlertService;
use OCA\NgBackup\Service\TargetService;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Accept the state a location shows now, after NG Backup refused it as rolled back. Only for
 * legitimate cases (e.g. the location was restored from the provider's own snapshot), only from
 * occ, audited and announced to all administrators.
 */
final class TargetTrustCurrent extends Command {
	public function __construct(
		private TargetService $targets,
		private IAppConfig $appConfig,
		private AlertService $alerts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:trust-current')
			->setDescription('Accept the current state of a location that was refused as rolled back')
			->addArgument('target', InputArgument::REQUIRED, 'Location name or id')
			->setHelp("NG Backup refuses a location whose backup catalog is older than what this server saw\n"
				. "before, because an attacker could have restored an old state. If you restored the location\n"
				. "yourself (e.g. from the provider's snapshot), accept it with this command.");
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$t = $this->targets->get($input->getArgument('target'));
		$output->writeln('<comment>Only continue if you know why the location shows an older state.</comment>');
		$answer = (string)$this->getHelper('question')->ask($input, $output, new Question('Type the location name to accept its current state: '));
		if ($answer !== $t->getName()) {
			$output->writeln('<error>Not confirmed.</error>');
			return 1;
		}
		$this->appConfig->deleteKey('ng_backup', 'catalog_' . preg_replace('/[^a-f0-9]/', '', (string)$t->getRepositoryId()));
		$repo = $this->targets->repository($t); // re-anchors on the newest valid catalog
		$this->alerts->securityEvent('catalog_trusted', ['target' => $t->getName(), 'generation' => (string)$repo->catalogGeneration()]);
		$output->writeln(sprintf('Accepted generation %d of %s (%d snapshots).', $repo->catalogGeneration(), $t->getName(), count($repo->listSnapshots())));
		return 0;
	}
}
