<?php

declare(strict_types=1);

// Spike E: per-user backup/restore through user_migration with our own export/import classes.
//   php -d memory_limit=128M /var/www/html/custom_apps/ng_backup/spikes/05-user-migration.php

require '/var/www/html/lib/base.php';
set_exception_handler(null);
restore_error_handler();
require __DIR__ . '/bootstrap.php';

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\UserMigration\RepositoryExportDestination;
use OCA\NgBackup\UserMigration\RepositoryImportSource;
use OCA\UserMigration\Service\UserMigrationService;
use OCP\Files\IRootFolder;
use OCP\IUserManager;

\OC_App::loadApps();
$ok = true;
$check = static function (string $name, bool $pass) use (&$ok): void {
	printf("%-66s %s\n", $name, $pass ? 'PASS' : 'FAIL');
	$ok = $ok && $pass;
};

$users = \OCP\Server::get(IUserManager::class);
$root = \OCP\Server::get(IRootFolder::class);
$caldav = \OCP\Server::get(CalDavBackend::class);
$carddav = \OCP\Server::get(CardDavBackend::class);
$migration = \OCP\Server::get(UserMigrationService::class);
$uid = 'alice';
$principal = 'principals/users/' . $uid;

if ($old = $users->get($uid)) {
	$old->delete();
}

// 1. Test user with files, a calendar event, a contact and profile data.
$user = $users->createUser($uid, bin2hex(random_bytes(12)));
$user->setDisplayName('Alice Test');
$user->setSystemEMailAddress('alice@example.com');
$home = $root->getUserFolder($uid);
$docs = $home->newFolder('Projecten/2026');
$docs->newFile('plan.txt', "Plan voor NG Backup\n");
$big = fopen('php://temp', 'w+b');
for ($i = 0; $i < 20; $i++) {
	fwrite($big, random_bytes(1048576));
}
rewind($big);
$home->newFile('Projecten/video.bin', $big);
$calId = $caldav->createCalendar($principal, 'werk', ['{DAV:}displayname' => 'Werk']);
$caldav->createCalendarObject($calId, 'ev1.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ngb//spike//EN\r\nBEGIN:VEVENT\r\nUID:ngb-spike-1\r\nDTSTAMP:20261001T120000Z\r\nDTSTART:20261002T090000Z\r\nDTEND:20261002T100000Z\r\nSUMMARY:Overleg backup\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
$abId = $carddav->createAddressBook($principal, 'prive', ['{DAV:}displayname' => 'Privé']);
$carddav->createCard($abId, 'c1.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:ngb-spike-c1\r\nFN:Bob Voorbeeld\r\nEMAIL:bob@example.com\r\nEND:VCARD\r\n");

$state = static function () use ($users, $root, $caldav, $carddav, $uid, $principal): array {
	$u = $users->get($uid);
	// Read from the data directory: IRootFolder caches the folder of a deleted user.
	$dir = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('datadirectory') . '/' . $uid . '/files';
	$files = [];
	if (is_dir($dir)) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if ($f->isFile()) {
				$files[substr($f->getPathname(), strlen($dir) + 1)] = hash_file('sha256', $f->getPathname());
			}
		}
	}
	ksort($files);
	$events = [];
	foreach ($caldav->getCalendarsForUser($principal) as $cal) {
		foreach ($caldav->getCalendarObjects($cal['id']) as $o) {
			$events[] = $cal['uri'] . '/' . $o['uri'];
		}
	}
	$cards = [];
	foreach ($carddav->getAddressBooksForUser($principal) as $ab) {
		foreach ($carddav->getCards($ab['id']) as $c) {
			$cards[] = $ab['uri'] . '/' . $c['uri'];
		}
	}
	sort($events);
	sort($cards);
	return ['display' => $u?->getDisplayName(), 'email' => $u?->getSystemEMailAddress(), 'files' => $files, 'events' => $events, 'cards' => $cards];
};
$before = $state();
printf("user %s: %d files, %d events, %d cards\n", $uid, count($before['files']), count($before['events']), count($before['cards']));
printf("migrators: %s\n", implode(', ', array_map(fn ($m) => $m->getId(), $migration->getMigrators())));

// 2. Export into the repository.
$repoDir = '/tmp/ngb-e-repo';
exec('rm -rf ' . $repoDir);
$repo = Repository::init(new LocalBackend($repoDir), 'spike passphrase');
memory_reset_peak_usage();
$t = microtime(true);
$dest = new RepositoryExportDestination($repo, $uid);
$migration->export($dest, $users->get($uid));
printf("export: %.1fs, peak %.1f MiB, %s\n", microtime(true) - $t, memory_get_peak_usage() / 1048576, json_encode($dest->stats()));
$manifest = $dest->manifestPath();
$check('export manifest written', in_array($manifest, array_map(fn ($p) => $p, (new LocalBackend($repoDir))->list('users')), true));

// Second export without changes: blobs are reused.
$dest2 = new RepositoryExportDestination($repo, $uid);
$migration->export($dest2, $users->get($uid));
printf("second export: %s\n", json_encode($dest2->stats()));
$check('unchanged second export stores (almost) nothing', $dest2->stats()['uploaded'] < 0.05 * $dest->stats()['uploaded']);

// 3. Delete the user and restore from the repository into a new account.
$users->get($uid)->delete();
$check('user deleted', $users->get($uid) === null);
$repo = Repository::open(new LocalBackend($repoDir), 'spike passphrase');
memory_reset_peak_usage();
$t = microtime(true);
$migration->import(new RepositoryImportSource($repo, $manifest));
printf("import: %.1fs, peak %.1f MiB\n", microtime(true) - $t, memory_get_peak_usage() / 1048576);
$after = $state();
$check('restored: display name and e-mail', $after['display'] === $before['display'] && $after['email'] === $before['email']);
$check('restored: all files identical', $after['files'] === $before['files']);
$check('restored: calendar event', count($after['events']) === count($before['events']));
$check('restored: contact', count($after['cards']) === count($before['cards']));
if ($after !== $before) {
	echo json_encode(['before' => $before, 'after' => $after], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

// 4. Restore over the existing (now restored) user: what happens?
try {
	$migration->import(new RepositoryImportSource($repo, $manifest), $users->get($uid));
	$again = $state();
	printf("import over existing user: files %d -> %d, events %d -> %d, cards %d -> %d\n",
		count($after['files']), count($again['files']), count($after['events']), count($again['events']), count($after['cards']), count($again['cards']));
	echo 'calendars now: ' . implode(', ', array_map(fn ($c) => $c['uri'], $caldav->getCalendarsForUser($principal))) . "\n";
} catch (\Throwable $e) {
	printf("import over existing user fails: %s: %s\n", get_class($e), $e->getMessage());
}

if (getenv("KEEP") !== "1") {
	$users->get($uid)?->delete();
}
exec('rm -rf ' . $repoDir);
echo $ok ? "ALL PASS\n" : "FAILURES\n";
exit($ok ? 0 : 1);
