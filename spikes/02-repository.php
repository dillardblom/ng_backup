<?php

declare(strict_types=1);

// Spike B: repository format, deduplication, incremental runs, restore, tamper detection.
// Usage: php -d memory_limit=128M spikes/02-repository.php [workdir]

require __DIR__ . '/bootstrap.php';

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\CryptoException;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Repository\RepositoryException;

$work = rtrim($argv[1] ?? sys_get_temp_dir(), '/') . '/ngb-spike-b';
exec('rm -rf ' . escapeshellarg($work));
$src = "$work/src";
$repoDir = "$work/repo";
mkdir($src, 0700, true);

$ok = true;
$check = static function (string $name, bool $pass) use (&$ok): void {
	printf("%-62s %s\n", $name, $pass ? 'PASS' : 'FAIL');
	$ok = $ok && $pass;
};
$write = static function (string $path, string $data, bool $append = false): void {
	@mkdir(dirname($path), 0700, true);
	file_put_contents($path, $data, $append ? FILE_APPEND : 0);
};
$randomFile = static function (string $path, int $mib): void {
	@mkdir(dirname($path), 0700, true);
	$fh = fopen($path, 'wb');
	for ($i = 0; $i < $mib; $i++) {
		fwrite($fh, random_bytes(1048576));
	}
	fclose($fh);
};
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
$du = static function (string $dir): int {
	$total = 0;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$total += $f->isFile() ? $f->getSize() : 0;
	}
	return $total;
};
$mb = static fn (int $b): string => sprintf('%.1f MiB', $b / 1048576);

// Source tree.
for ($i = 0; $i < 200; $i++) {
	$write(sprintf('%s/docs/%02d/note-%03d.txt', $src, $i % 10, $i), str_repeat("Regel $i van een tekstbestand met herhaling. ", 1200));
}
for ($i = 0; $i < 20; $i++) {
	$randomFile("$src/photos/img-$i.jpg", 1 + $i % 5);
}
$randomFile("$src/video/big.mp4", 300);
$randomFile("$src/copies/a.bin", 10);
copy("$src/copies/a.bin", "$src/copies/b.bin");
$sourceBytes = $du($src);
$state1 = $hashTree($src);
printf("source: %d files, %s\n", count($state1), $mb($sourceBytes));

$backend = new LocalBackend($repoDir);
$repo = Repository::init($backend, 'spike passphrase');

// Run 1: full.
memory_reset_peak_usage();
$t = microtime(true);
$r1 = $repo->backupDirectory($src, null, 'run 1');
printf("run 1: %.1fs, peak %s, %s\n", microtime(true) - $t, $mb(memory_get_peak_usage()), json_encode(array_diff_key($r1, ['snapshot' => 1])));
$repoAfter1 = $du($repoDir);
printf("repository after run 1: %s (%.0f%% of source)\n", $mb($repoAfter1), $repoAfter1 / $sourceBytes * 100);
$check('run 1: identical copies stored once (dedup within run)', $r1['dupBlobs'] >= 3);
$check('run 1: repository smaller than source (compression + dedup)', $repoAfter1 < $sourceBytes);
$check('run 1: peak memory < 64 MiB with a 300 MiB file', memory_get_peak_usage() < 64 * 1048576);

// Run 2: nothing changed.
$t = microtime(true);
$r2 = $repo->backupDirectory($src, $r1['snapshot'], 'run 2');
printf("run 2: %.2fs, %s\n", microtime(true) - $t, json_encode(array_diff_key($r2, ['snapshot' => 1])));
$check('run 2: no file read, nothing uploaded', $r2['read'] === 0 && $r2['uploaded'] === 0);

// Changes: append to a note, overwrite 1 MiB in the middle of the big file, add, delete.
sleep(1); // mtime resolution
$write("$src/docs/03/note-003.txt", "Toegevoegde regel.\n", true);
$fh = fopen("$src/video/big.mp4", 'r+b');
fseek($fh, 150 * 1048576);
fwrite($fh, random_bytes(1048576));
fclose($fh);
$randomFile("$src/photos/new.jpg", 2);
unlink("$src/photos/img-0.jpg");
$state3 = $hashTree($src);

$t = microtime(true);
$r3 = $repo->backupDirectory($src, $r2['snapshot'], 'run 3');
printf("run 3: %.1fs, %s\n", microtime(true) - $t, json_encode(array_diff_key($r3, ['snapshot' => 1])));
$check('run 3: only changed/new files read (3)', $r3['read'] === 3);
$check('run 3: big file change costs ~1 blob, not 300 MiB', $r3['uploaded'] < 12 * 1048576);

// Restore with a fresh process view of the repository (reopen with the passphrase).
$repo = Repository::open($backend, 'spike passphrase');
$check('3 snapshots listed', count($repo->listSnapshots()) === 3);

memory_reset_peak_usage();
$repo->restore($r1['snapshot'], "$work/restore1");
$check('restore run 1: all files identical to state 1', $hashTree("$work/restore1") === $state1);
printf("restore peak memory: %s\n", $mb(memory_get_peak_usage()));
$repo->restore($r3['snapshot'], "$work/restore3");
$check('restore run 3: all files identical to state 3', $hashTree("$work/restore3") === $state3);
$n = $repo->restore($r3['snapshot'], "$work/restore-docs", 'docs/03/');
$check('partial restore of one folder (20 files)', $n === 20);

// Wrong passphrase.
try {
	Repository::open($backend, 'wrong');
	$check('wrong passphrase rejected', false);
} catch (CryptoException) {
	$check('wrong passphrase rejected', true);
}

// Tamper with one pack: restore must fail loudly, not produce wrong data.
$packs = $backend->list('packs');
$victim = "$repoDir/" . $packs[0];
$bytes = file_get_contents($victim);
$bytes[1000] = chr(ord($bytes[1000]) ^ 0xff);
file_put_contents($victim, $bytes);
try {
	$repo->restore($r1['snapshot'], "$work/restore-bad");
	$check('tampered pack detected on restore', false);
} catch (CryptoException|RepositoryException $e) {
	$check('tampered pack detected on restore', true);
}

exec('rm -rf ' . escapeshellarg($work));
echo $ok ? "ALL PASS\n" : "FAILURES\n";
exit($ok ? 0 : 1);
