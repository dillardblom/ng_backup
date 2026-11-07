<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\TargetService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class TargetAdd extends Command {
	public function __construct(
		private TargetService $targets,
		private KeyService $keys,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('backup:target:add')
			->setDescription('Add a backup location (S3, SFTP, WebDAV, ...) via files_external, without mounting it')
			->addArgument('name', InputArgument::REQUIRED, 'Name of the location')
			->addArgument('backend', InputArgument::REQUIRED, 'files_external backend id (see backup:target:backends)')
			->addOption('auth', 'a', InputOption::VALUE_REQUIRED, 'Auth mechanism id (see backup:target:backends)')
			->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Backend/auth option key=value (repeatable)')
			->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Folder on the location for this repository', 'ng_backup')
			->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Disaster recovery without the recovery kit: read the passphrase from this file instead of asking')
			->addOption('repair-config', null, InputOption::VALUE_NONE, 'The location\'s config file is gone: write it again with the key of this server or of the imported recovery kit (locations backed up by 1.1.0 or newer)')
			->setHelp(<<<'HELP'
The location is reached with files_external's own connection code, but it is never added as an
external storage: it does not appear in Files for anyone. Credentials are stored encrypted.

Disaster recovery without the recovery kit: on a server without a backup key, a location that
holds backups gives its key with one of the old server's passphrases. Run this command first
(no occ backup:key:import), then occ backup:list and occ backup:restore:full.

With the recovery kit: occ backup:key:import first; this command then asks for nothing more.
If the location's config file is gone, only the kit has the key: import it, then add the
location with --repair-config.

Examples:
  occ backup:target:add offsite amazons3 -a amazons3::accesskey       -o bucket=backups -o hostname=s3.example.com -o region=eu-central-1       -o use_ssl=true -o use_path_style=true -o key=AKIA... -o secret=... --path=nextcloud

  occ backup:target:add nas sftp -a password::password       -o host=nas.example.lan -o root=/backups -o user=nextcloud -o password=... --path=ng_backup
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$options = [];
		foreach ($input->getOption('option') as $pair) {
			if (!str_contains($pair, '=')) {
				$output->writeln("<error>Option '$pair' must be key=value</error>");
				return 1;
			}
			[$k, $v] = explode('=', $pair, 2);
			$options[$k] = match (strtolower($v)) { 'true' => true, 'false' => false, default => $v };
		}
		if ($input->getOption('auth') === null) {
			$output->writeln('<error>--auth is required (see occ backup:target:backends)</error>');
			return 1;
		}
		$name = (string)$input->getArgument('name');
		$backend = (string)$input->getArgument('backend');
		$auth = (string)$input->getOption('auth');
		$path = (string)$input->getOption('path');
		$passphrase = null;
		$repair = (bool)$input->getOption('repair-config');
		if (!$this->keys->isInitialized()) {
			try {
				$state = $this->targets->locationState($backend, $auth, $options, $path);
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			if ($state === TargetService::LOCATION_EMPTY) {
				$output->writeln('<error>No backup key yet, and no backups at this location.</error>');
				$output->writeln('Restoring a server (disaster recovery)? Check the connection details and --path: they must be the same as on the old server.');
				$output->writeln('Only for a new location: create a new key first with occ backup:key:init. A new key cannot read existing backups.');
				return 1;
			}
			if ($state === TargetService::LOCATION_DATA_WITHOUT_CONFIG) {
				$output->writeln('<error>This location holds backups, but its config file is gone, and the key was stored in it.</error>');
				$output->writeln('Now only the recovery kit has the key. Import it, then run this command again with --repair-config:');
				$output->writeln('  occ backup:key:import /path/to/ng_backup-kit.json');
				$output->writeln('Do not run occ backup:key:init: a new key cannot read these backups.');
				return 1;
			}
			if ($repair) {
				$output->writeln('<error>The config at this location is there: leave out --repair-config.</error>');
				return 1;
			}
			$output->writeln('No backup key on this server yet, and this location holds backups. Their key is stored there too,');
			$output->writeln('locked with the passphrases of the old server: one of them unlocks it (the recovery kit is not needed).');
			$passphrase = $this->passphrase($input, $output);
			if ($passphrase === null) {
				return 1;
			}
		}
		// Adopting an existing repository reads its whole index first, which takes a while on a
		// large backup or a slow line; show how far it is instead of looking stuck.
		$output->writeln('Connecting to the location; with existing backups this reads their index first ...');
		try {
			$r = $passphrase === null
				? $this->targets->add($name, $backend, $auth, $options, $path, self::progress($output), $repair)
				: $this->targets->addWithPassphrase($name, $backend, $auth, $options, $path, $passphrase, self::progress($output));
		} catch (\OCA\NgBackup\Crypto\CryptoException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			if (!$this->keys->isInitialized()) {
				$output->writeln('Nothing was changed. Run the command again to try another passphrase, or use the recovery kit (occ backup:key:import).');
			}
			return 1;
		} catch (\Throwable $e) {
			if ($output->isDecorated()) {
				$output->write("\r\033[K"); // clear an unfinished progress line
			}
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			if (str_contains($e->getMessage(), 'different key')) {
				$output->writeln('The backup key here (fingerprint ' . $this->keys->fingerprint() . ') is not the one these backups were made with.');
				$output->writeln('Restoring a server? The recovery kit must be the old server\'s: its fingerprint is in the kit file. Nothing was changed.');
			}
			return 1;
		}
		if ($repair) {
			$output->writeln('The config of the location was written again from the recovery kit.');
		}
		$output->writeln(sprintf('Location <info>%s</info> added (id %d), repository %s %s.', $r['target']->getName(), $r['target']->getId(),
			$r['target']->getRepositoryId(), $r['created'] ? 'created' : 'found and adopted'));
		if ($passphrase !== null) {
			$output->writeln('Backup key taken from the location, fingerprint <info>' . $this->keys->fingerprint() . '</info>.');
			$output->writeln(array_key_exists('deleteDelayDays', $r) && $r['deleteDelayDays'] !== null
				? 'Deletion delay for forgotten snapshots: <info>' . $this->keys->deleteDelayDays() . '</info> days (from the location).'
				: 'Deletion delay for forgotten snapshots: <info>' . $this->keys->deleteDelayDays() . '</info> days (the default: the location has none stored, its backups were made before NG Backup 1.1.0).');
			$output->writeln('Next: <info>occ backup:list</info> and <info>occ backup:restore:full</info>. Before backups run again, download a new recovery kit and confirm it (<info>occ backup:key:kit</info>, <info>occ backup:key:confirm</info>).');
		}
		return 0;
	}

	private function passphrase(InputInterface $input, OutputInterface $output): ?string {
		$file = $input->getOption('passphrase-file');
		if ($file !== null) {
			$raw = @file_get_contents((string)$file);
			if ($raw === false) {
				$output->writeln('<error>Cannot read the passphrase file</error>');
				return null;
			}
			return rtrim($raw, "\r\n");
		}
		if (!$input->isInteractive()) {
			$output->writeln('<error>Give the passphrase with --passphrase-file, or run the command without --no-interaction to type it.</error>');
			return null;
		}
		$q = (new Question('Passphrase of the old server (one of its slots): '))->setHidden(true)->setHiddenFallback(false);
		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		return (string)$helper->ask($input, $output, $q);
	}

	/**
	 * Progress of reading the index: on a terminal one line that is updated in place, otherwise
	 * (a log, a pipe) a line every 30 seconds. The time left is estimated from the rate so far.
	 *
	 * @return callable(int, int): void
	 */
	private static function progress(OutputInterface $output): callable {
		$shown = 0.0;
		$first = null; // [time, files done] when the first files were in
		$inPlace = $output->isDecorated();
		return static function (int $done, int $total) use ($output, &$shown, &$first, $inPlace): void {
			$now = microtime(true);
			if ($first === null && $done > 0) {
				$first = [$now, $done];
			}
			if ($done < $total && $now - $shown < ($inPlace ? 1 : 30)) {
				return;
			}
			$shown = $now;
			$line = sprintf('Reading the index: %s/%s files (%d%%)', number_format($done), number_format($total), intdiv(100 * $done, max(1, $total)));
			$left = $first === null ? null : self::minutesLeft($now - $first[0], $done - $first[1], $total - $done, $total);
			if ($left !== null) {
				$line .= $left <= 1 ? ', less than a minute left' : sprintf(', about %d min left', $left);
			}
			if ($inPlace) {
				$output->write("\r\033[K" . $line . ($done >= $total ? "\n" : ''));
			} else {
				$output->writeln($line);
			}
		};
	}

	/**
	 * Minutes left for reading the index, from the rate since the first files were in: the first
	 * file also carries the time to connect, which made an S3 location with 4,568 index files
	 * say "about 642 min left" for something done within a minute. No estimate until 5% of the
	 * files, or 50 files, came in after that, and at least 5 seconds passed.
	 */
	public static function minutesLeft(float $elapsed, int $done, int $left, int $total): ?int {
		if ($left <= 0 || $done <= 0 || $elapsed < 5.0 || ($done < 50 && $done * 20 < $total)) {
			return null;
		}
		return (int)ceil($elapsed / (float)$done * (float)$left / 60.0);
	}
}
