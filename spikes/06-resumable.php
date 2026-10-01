<?php

declare(strict_types=1);

// Spike F: resumable, time-boxed backup steps in separate processes, surviving kill -9.
//   php 06-resumable.php prepare <work>
//   php 06-resumable.php step <work> <seconds>     (repeat until it prints "done")
//   php 06-resumable.php verify <work>

require __DIR__ . '/bootstrap.php';

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Job\BackupRun;
use OCA\NgBackup\Repository\Repository;

[$_, $mode, $work] = $argv + [null, null, '/tmp/ngb-f'];
$src = "$work/src";
$stateFile = "$work/state.json";
$pass = 'spike passphrase';

$hashTree = static function (string $root): array {
	$out = [];
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if ($f->isFile()) {
			$out[substr($f->getPathname(), strlen($root) + 1)] = hash_file('sha256', $f->getPathname());
		}
	}
	ksort($out);
	return $out;
};

switch ($mode) {
	case 'prepare':
		exec('rm -rf ' . escapeshellarg($work));
		mkdir("$src/docs", 0700, true);
		for ($i = 0; $i < 400; $i++) {
			file_put_contents(sprintf('%s/docs/n%03d.txt', $src, $i), random_bytes(30000) . str_repeat('x', 30000));
		}
		$fh = fopen("$src/huge.bin", 'wb');
		for ($i = 0; $i < 200; $i++) {
			fwrite($fh, random_bytes(1048576));
		}
		fclose($fh);
		Repository::init(new LocalBackend("$work/repo"), $pass);
		file_put_contents("$work/expected.json", json_encode($hashTree($src)));
		file_put_contents($stateFile, json_encode(BackupRun::start($src, null, 'resumable')));
		echo "prepared\n";
		break;

	case 'step':
		$seconds = (float)($argv[3] ?? 1.5);
		$state = json_decode(file_get_contents($stateFile), true);
		if ($state['phase'] === 'done') {
			echo "done\n";
			break;
		}
		$repo = Repository::open(new LocalBackend("$work/repo"), $pass);
		$t = microtime(true);
		$state = BackupRun::step($repo, $state, $t + $seconds);
		// Simulate a user overwriting the big file (same size) while the run is in the middle of it.
		if (getenv('NGB_MUTATE') === '1' && ($state['cur']['path'] ?? '') === 'huge.bin' && !file_exists("$work/mutated")) {
			$fh = fopen("$src/huge.bin", 'r+b');
			fseek($fh, 10 * 1048576);
			fwrite($fh, random_bytes(1048576));
			fclose($fh);
			touch("$src/huge.bin", time() + 5);
			file_put_contents("$work/expected.json", json_encode($hashTree($src)));
			touch("$work/mutated");
			echo "  -> huge.bin overwritten (same size) between steps\n";
		}
		// Save the state atomically: a kill before this point means the step is simply redone.
		file_put_contents("$stateFile.tmp", json_encode($state));
		rename("$stateFile.tmp", $stateFile);
		printf("step %d: %.1fs, phase %s, file %d/%d%s, peak %.0f MiB\n", $state['steps'], microtime(true) - $t, $state['phase'],
			$state['pos'], max(count($state['files']), $state['pos']),
			$state['cur'] ? sprintf(' (in %s at %d MiB)', $state['cur']['path'], $state['cur']['offset'] / 1048576) : '',
			memory_get_peak_usage() / 1048576);
		if ($state['phase'] === 'done') {
			echo 'done ' . json_encode($state['stats']) . "\n";
		}
		break;

	case 'verify':
		$state = json_decode(file_get_contents($stateFile), true);
		$repo = Repository::open(new LocalBackend("$work/repo"), $pass);
		exec('rm -rf ' . escapeshellarg("$work/restore"));
		$repo->restore($state['snapshot'], "$work/restore");
		$same = $hashTree("$work/restore") === json_decode(file_get_contents("$work/expected.json"), true);
		printf("%-60s %s\n", 'restored tree identical after resumable run with kills', $same ? 'PASS' : 'FAIL');
		exec('rm -rf ' . escapeshellarg($work));
		exit($same ? 0 : 1);
}
