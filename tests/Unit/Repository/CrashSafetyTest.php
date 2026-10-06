<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Job\BackupRun;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Repository\RepositoryException;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/** Data-safety cases found in the pre-beta review. */
class CrashSafetyTest extends TestCase {
	use TempDirTrait;

	public function testListingKeepsNamesThatOnlyLookLikeTemporaryFiles(): void {
		$root = $this->tempDir();
		$backend = new LocalBackend($root);
		mkdir("$root/users/john.part-time", 0700, true);
		file_put_contents("$root/users/john.part-time/20261006T120000Z-aaaa0001", 'x');
		file_put_contents("$root/users/upload.part-deadbeef", 'partial');
		$this->assertSame(['users/john.part-time/20261006T120000Z-aaaa0001'], $backend->list('users'));
	}

	public function testPruneStopsWhenARecordedExportManifestIsMissing(): void {
		$root = $this->tempDir();
		$backend = new LocalBackend($root);
		$keys = KeyRing::generate();
		$repo = Repository::initWithKey($backend, $keys, $keys->wrap('pw'));
		$stats = [];
		$blob = $repo->storeData(random_bytes(1000), $stats);
		$repo->flushPacks();
		$path = 'users/alice/20261006T120000Z-aaaa0001';
		$repo->putObject($path, json_encode(['uid' => 'alice', 'entries' => ['f.txt' => ['t' => 'f', 'b' => [$blob], 's' => 1000]]]));
		$repo->recordUserExport($path);
		unlink("$root/$path");

		$packsBefore = $backend->list('packs');
		try {
			Repository::openWithKey($backend, $keys)->prune();
			$this->fail('prune must refuse while a recorded export is missing');
		} catch (RepositoryException $e) {
			$this->assertStringContainsString('missing on the location', $e->getMessage());
		}
		$this->assertSame($packsBefore, $backend->list('packs'), 'no pack was deleted');
	}

	public function testForgetAndUntrashKeepTheSnapshotRestorable(): void {
		$src = $this->tempDir();
		file_put_contents("$src/a.txt", 'hello');
		$root = $this->tempDir();
		$backend = new LocalBackend($root);
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));
		$state = BackupRun::start($src);
		while ($state['phase'] !== 'commit') {
			$state = BackupRun::step(Repository::openWithKey($backend, $keys), $state, microtime(true) + 5);
		}
		Repository::openWithKey($backend, $keys)->recordSnapshot($state['snapshot']);
		$id = $state['snapshot'];

		Repository::openWithKey($backend, $keys)->forget($id, 'test');
		$repo = Repository::openWithKey($backend, $keys);
		$this->assertSame([], $repo->listSnapshots());
		$this->assertArrayHasKey($id, $repo->trash());
		$this->assertFalse($backend->exists('snapshots/' . $id), 'original removed after the catalog switch');

		$repo->untrash($id);
		$repo = Repository::openWithKey($backend, $keys);
		$this->assertSame([$id], $repo->listSnapshots());
		$this->assertSame([], $repo->trash());
		$target = $this->tempDir();
		$repo->restore($id, $target);
		$this->assertSame('hello', file_get_contents("$target/a.txt"));
	}

	public function testFileThatKeepsChangingDoesNotStallTheRun(): void {
		$src = $this->tempDir();
		file_put_contents("$src/live.db", random_bytes(3 * Repository::BLOB_SIZE));
		$root = $this->tempDir();
		$backend = new LocalBackend($root);
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));

		$state = BackupRun::start($src);
		$n = 0;
		for ($i = 0; $i < 50 && $state['phase'] !== 'commit'; $i++) {
			$state = BackupRun::step(Repository::openWithKey($backend, $keys), $state, microtime(true) + 0.001);
			// Rewrite the file after every step: it never stays the same between two steps.
			$fh = fopen("$src/live.db", 'r+b');
			fwrite($fh, random_bytes(16));
			fclose($fh);
			touch("$src/live.db", time() + ++$n);
			clearstatcache();
		}
		$this->assertSame('commit', $state['phase'], 'the run finishes instead of retrying forever');
		$this->assertSame(1, $state['stats']['changing'] ?? 0);
		$this->assertSame(['live.db'], BackupRun::warnings($state)['changing']);
	}
}
