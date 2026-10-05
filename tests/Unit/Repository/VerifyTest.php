<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class VerifyTest extends TestCase {
	use TempDirTrait;

	private LocalBackend $backend;
	private KeyRing $keys;
	private string $root;
	private string $src;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->backend = new LocalBackend($this->root);
		$this->keys = KeyRing::generate();
		$this->src = $this->tempDir();
		file_put_contents("$this->src/a", random_bytes(100000));
		file_put_contents("$this->src/b", random_bytes(100000));
	}

	private function open(): Repository {
		return Repository::openWithKey($this->backend, $this->keys, null, null);
	}

	public function testShallowVerifyPassesOnAHealthyRepository(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$s = $repo->backupDirectory($this->src);

		$result = $this->open()->verify($s['snapshot']);

		$this->assertSame(2, $result['filesChecked']);
		$this->assertGreaterThanOrEqual(1, $result['blobsChecked']);
		$this->assertSame(0, $result['bytesChecked']); // not downloaded without --deep
		$this->assertSame([], $result['missing']);
		$this->assertSame([], $result['failed']);
	}

	public function testDeepVerifyDownloadsAndCountsBytes(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$s = $repo->backupDirectory($this->src);

		$result = $this->open()->verify($s['snapshot'], deep: true);

		$this->assertSame(200000, $result['bytesChecked']);
		$this->assertSame([], $result['missing']);
		$this->assertSame([], $result['failed']);
	}

	public function testShallowVerifyDetectsAMissingPack(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$s = $repo->backupDirectory($this->src);
		foreach ($this->backend->list('packs') as $pack) {
			$this->backend->delete($pack);
		}

		$result = $this->open()->verify($s['snapshot']);

		$this->assertNotSame([], $result['missing']);
	}

	public function testVerifyFailsOnAMissingDatabaseManifest(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$repo->writeSnapshot('snap-no-db', ['time' => gmdate('c'), 'db' => 'db/does-not-exist'], []);

		$result = $this->open()->verify('snap-no-db');

		$this->assertSame(['db/does-not-exist'], $result['missing']);
	}

	public function testDeepVerifyDetectsATamperedPack(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$s = $repo->backupDirectory($this->src);
		$packs = $this->backend->list('packs');
		$this->assertNotEmpty($packs);
		$path = $this->root . '/' . $packs[0];
		$bytes = file_get_contents($path);
		// Flip a byte well inside the file, away from any header, so decryption's own
		// authentication tag catches it (this must fail loudly, not just "do nothing").
		$bytes[(int)(strlen($bytes) / 2)] = chr(~ord($bytes[(int)(strlen($bytes) / 2)]) & 0xFF);
		file_put_contents($path, $bytes);

		$result = $this->open()->verify($s['snapshot'], deep: true);

		$this->assertNotSame([], $result['failed']);
	}
}
