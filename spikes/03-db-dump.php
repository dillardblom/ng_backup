<?php

declare(strict_types=1);

// Spike C: logical DB dump/restore through the Nextcloud connection (no pg_dump/mysqldump).
// Run inside a Nextcloud container as the web user:
//   php -d memory_limit=128M /var/www/html/custom_apps/ng_backup/spikes/03-db-dump.php /tmp/ngb-db-repo

require '/var/www/html/lib/base.php';
set_exception_handler(null);
restore_error_handler();
require __DIR__ . '/bootstrap.php';

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Db\DbDumper;
use OCA\NgBackup\Repository\Repository;

$repoDir = $argv[1] ?? '/tmp/ngb-db-repo';
exec('rm -rf ' . escapeshellarg($repoDir));

$ok = true;
$check = static function (string $name, bool $pass) use (&$ok): void {
	printf("%-62s %s\n", $name, $pass ? 'PASS' : 'FAIL');
	$ok = $ok && $pass;
};

/** @var \OC\DB\ConnectionAdapter $adapter */
$adapter = \OCP\Server::get(\OCP\IDBConnection::class);
$db = $adapter->getInner();
$prefix = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
$dumper = new DbDumper($db, $prefix);
printf("database: %s, prefix %s\n", get_class($db->getDatabasePlatform()), $prefix);

$tables = array_values(array_filter($db->createSchemaManager()->listTableNames(), fn ($t) => str_starts_with($t, $prefix)));
sort($tables);
// Fixture the damage step relies on, so the check does not depend on existing data.
$db->executeStatement('DELETE FROM ' . $prefix . 'preferences WHERE userid = ? AND appid = ? AND configkey = ?', ['admin', 'core', 'lang']);
$db->insert($prefix . 'preferences', ['userid' => 'admin', 'appid' => 'core', 'configkey' => 'lang', 'configvalue' => 'nl']);
$seqStates = static function () use ($db, $prefix): array {
	if (!$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
		return [];
	}
	$out = [];
	foreach ($db->fetchFirstColumn("SELECT schemaname || '.' || sequencename FROM pg_sequences WHERE sequencename LIKE ? ORDER BY 1", [$prefix . '%']) as $s) {
		$r = $db->fetchAssociative('SELECT last_value, is_called FROM ' . $s);
		$out[$s] = [(int)$r['last_value'], (bool)$r['is_called']];
	}
	return $out;
};
// Let one sequence run ahead of the data (deleted rows, rollbacks): MAX+1 would be wrong.
$fcSeq = $db->fetchOne("SELECT pg_get_serial_sequence(?, 'fileid')", [$prefix . 'filecache']);
for ($i = 0; $i < 50; $i++) {
	$db->fetchOne('SELECT nextval(CAST(? AS regclass))', [$fcSeq]);
}
$seqBefore = $seqStates();
$before = [];
foreach ($tables as $t) {
	$before[$t] = $dumper->checksum($t);
}
printf("%d tables, %d rows\n", count($tables), array_sum(array_column($before, 'rows')));

$repo = Repository::init(new LocalBackend($repoDir), 'spike passphrase');

memory_reset_peak_usage();
$stats = ['newBlobs' => 0, 'dupBlobs' => 0, 'uploaded' => 0];
$t = microtime(true);
$manifest = $dumper->dump($repo, $stats);
$repo->putObject('db/1', json_encode($manifest, JSON_THROW_ON_ERROR));
printf("dump 1: %.1fs, peak %.1f MiB, %s\n", microtime(true) - $t, memory_get_peak_usage() / 1048576, json_encode($stats));
$dumpBytes = array_sum(array_column($manifest['tables'], 'bytes'));
printf("dump size (plain JSON lines): %.1f MiB\n", $dumpBytes / 1048576);
$check('checksums in dump match live tables', array_map(fn ($x) => $x['sha256'], $manifest['tables']) === array_map(fn ($x) => $x['sha256'], $before));

// Second dump without changes: blobs are reused.
$stats2 = ['newBlobs' => 0, 'dupBlobs' => 0, 'uploaded' => 0];
$dumper->dump($repo, $stats2);
printf("dump 2 (no changes): %s\n", json_encode($stats2));
$check('unchanged database: (almost) nothing new stored', $stats2['uploaded'] < 0.05 * max(1, $stats['uploaded']));

// Damage the database.
$db->executeStatement('DELETE FROM ' . $prefix . 'preferences WHERE userid = ? AND appid = ? AND configkey = ?', ['admin', 'core', 'lang']);
$db->executeStatement('UPDATE ' . $prefix . 'appconfig SET configvalue = ? WHERE appid = ? AND configkey = ?', ['damaged', 'core', 'installedat']);
$db->insert($prefix . 'users', ['uid' => 'intruder', 'displayname' => 'Intruder', 'password' => 'x', 'uid_lower' => 'intruder']);
$changed = array_filter($tables, fn ($tb) => $dumper->checksum($tb)['sha256'] !== $before[$tb]['sha256']);
printf("tables changed by the damage: %s\n", implode(', ', $changed));
$check('damage visible in checksums', count($changed) >= 3);

// Restore.
memory_reset_peak_usage();
$t = microtime(true);
$repo2 = Repository::open(new LocalBackend($repoDir), 'spike passphrase');
$m = json_decode($repo2->getObject('db/1'), true, 512, JSON_THROW_ON_ERROR);
$rows = $dumper->restore($repo2, $m);
printf("restore: %d rows in %.1fs, peak %.1f MiB\n", $rows, microtime(true) - $t, memory_get_peak_usage() / 1048576);

$mismatch = [];
foreach ($tables as $tb) {
	$after = $dumper->checksum($tb);
	if ($after !== $before[$tb]) {
		$mismatch[] = $tb;
	}
}
$check('after restore: every table identical to before', $mismatch === []);
if ($mismatch) {
	echo 'mismatch: ' . implode(', ', $mismatch) . "\n";
}


// Sequences must be restored exactly (PostgreSQL), including one that ran ahead of the data.
$seqAfter = $seqStates();
$diff = array_filter(array_keys($seqBefore), fn ($k) => ($seqAfter[$k] ?? null) !== $seqBefore[$k]);
$check(sprintf('all %d sequences restored exactly (filecache seq 50 ahead of data)', count($seqBefore)), $diff === [] && count($seqBefore) > 0);
if ($diff) {
	foreach (array_slice($diff, 0, 5) as $k) {
		echo "$k before=" . json_encode($seqBefore[$k]) . ' after=' . json_encode($seqAfter[$k] ?? null) . "\n";
	}
}

exec('rm -rf ' . escapeshellarg($repoDir));
echo $ok ? "ALL PASS\n" : "FAILURES\n";
exit($ok ? 0 : 1);
