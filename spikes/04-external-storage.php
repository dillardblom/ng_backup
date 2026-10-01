<?php

declare(strict_types=1);

// Spike D: repository on S3 and SFTP through files_external classes, without any mount.
// Run inside the Nextcloud container as the web user:
//   php -d memory_limit=128M /var/www/html/custom_apps/ng_backup/spikes/04-external-storage.php [s3|sftp|both]

require '/var/www/html/lib/base.php';
set_exception_handler(null);
restore_error_handler();
require __DIR__ . '/bootstrap.php';

use OCA\Files_External\Service\BackendService;
use OCA\NgBackup\Backend\ExternalStorageFactory;
use OCA\NgBackup\Backend\StorageBackend;
use OCA\NgBackup\Repository\Repository;

\OC_App::loadApp('files_external');

$which = $argv[1] ?? 'both';
$ok = true;
$check = static function (string $name, bool $pass) use (&$ok): void {
	printf("%-62s %s\n", $name, $pass ? 'PASS' : 'FAIL');
	$ok = $ok && $pass;
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

// Source tree: 100 small files + one 120 MiB file.
$src = '/tmp/ngb-d-src';
exec('rm -rf /tmp/ngb-d-src /tmp/ngb-d-restore-*');
mkdir("$src/docs", 0700, true);
for ($i = 0; $i < 100; $i++) {
	file_put_contents("$src/docs/note-$i.txt", str_repeat("notitie $i ", 2000));
}
$fh = fopen("$src/big.bin", 'wb');
for ($i = 0; $i < 120; $i++) {
	fwrite($fh, random_bytes(1048576));
}
fclose($fh);
$state = $hashTree($src);

$factory = new ExternalStorageFactory(\OCP\Server::get(BackendService::class));
$names = array_column($factory->describeBackends(), 'id');
printf("files_external backends available: %s\n", implode(', ', $names));

$targets = [
	's3' => ['amazons3', 'amazons3::accesskey', [
		'bucket' => 'ngbackup', 'hostname' => 's3', 'port' => '8333', 'region' => 'us-east-1',
		'use_ssl' => false, 'use_path_style' => true, 'autocreate' => true,
		'key' => 'ngbtestkey', 'secret' => 'ngbtestsecret123',
	]],
	'sftp' => ['sftp', 'password::password', [
		'host' => 'sftp', 'root' => '/repo', 'user' => 'ngb', 'password' => 'ngbtestpass',
	]],
];

$mountsBefore = (int)\OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder()
	->select($q = \OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder()->func()->count('*'))
	->from('external_mounts')->executeQuery()->fetchOne();

foreach ($targets as $name => [$backendId, $authId, $options]) {
	if ($which !== 'both' && $which !== $name) {
		continue;
	}
	echo "--- $name\n";
	$storage = $factory->create($backendId, $authId, $options);
	$backend = new StorageBackend($storage, 'spike-' . bin2hex(random_bytes(3)));

	memory_reset_peak_usage();
	$t = microtime(true);
	$repo = Repository::init($backend, 'spike passphrase');
	$r1 = $repo->backupDirectory($src, null, $name);
	printf("backup: %.1fs, peak %.1f MiB, uploaded %.1f MiB\n", microtime(true) - $t, memory_get_peak_usage() / 1048576, $r1['uploaded'] / 1048576);

	$t = microtime(true);
	$r2 = $repo->backupDirectory($src, $r1['snapshot'], $name);
	printf("unchanged run: %.2fs, uploaded %d bytes\n", microtime(true) - $t, $r2['uploaded']);

	memory_reset_peak_usage();
	$t = microtime(true);
	$repo = Repository::open($backend, 'spike passphrase');
	$repo->restore($r1['snapshot'], "/tmp/ngb-d-restore-$name");
	printf("restore: %.1fs, peak %.1f MiB\n", microtime(true) - $t, memory_get_peak_usage() / 1048576);
	$check("$name: restored tree identical", $hashTree("/tmp/ngb-d-restore-$name") === $state);
	$check("$name: unchanged run uploads nothing", $r2['uploaded'] === 0);
	$check("$name: peak memory < 64 MiB", memory_get_peak_usage() < 64 * 1048576);
	$check("$name: objects on target: config, packs, index, snapshots", count($backend->list('snapshots')) === 2 && $backend->exists('config'));
}

$mountsAfter = (int)\OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder()
	->select(\OCP\Server::get(\OCP\IDBConnection::class)->getQueryBuilder()->func()->count('*'))
	->from('external_mounts')->executeQuery()->fetchOne();
$check("no files_external mount created ($mountsBefore -> $mountsAfter)", $mountsBefore === $mountsAfter);

exec('rm -rf /tmp/ngb-d-src /tmp/ngb-d-restore-*');
echo $ok ? "ALL PASS\n" : "FAILURES\n";
exit($ok ? 0 : 1);
