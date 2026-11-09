<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\CryptoException;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/** Disaster recovery without the recovery kit: the location gives its key with a passphrase. */
class KeyFromLocationTest extends TestCase {
	use TempDirTrait;

	private string $dir;
	private LocalBackend $backend;
	private KeyRing $keys;

	protected function setUp(): void {
		$this->dir = $this->tempDir();
		$this->backend = new LocalBackend($this->dir);
		$this->keys = KeyRing::generate();
		$slots = ['slots' => [
			['slot' => 1, 'label' => 'A', 'created' => 0, 'key' => $this->keys->wrap('first passphrase')],
			['slot' => 2, 'label' => 'B', 'created' => 0, 'key' => $this->keys->wrap('second passphrase')],
		]];
		Repository::initWithKey($this->backend, $this->keys, $slots)->recordSettings(['delete_delay_days' => 30]);
	}

	public function testEachPassphraseGivesTheKeyThatOpensTheBackups(): void {
		foreach (['first passphrase', 'second passphrase'] as $passphrase) {
			$keys = KeyRing::unwrapAny(Repository::wrappedKeyAt($this->backend), $passphrase);
			$this->assertTrue(Repository::madeWithKey($this->backend, $keys));
			$this->assertSame($this->keys->exportMasterKey(), $keys->exportMasterKey());
		}
	}

	public function testAWrongPassphraseGivesNothing(): void {
		$this->expectException(CryptoException::class);
		KeyRing::unwrapAny(Repository::wrappedKeyAt($this->backend), 'not the passphrase');
	}

	public function testAnotherKeyIsNotTakenForTheRightOne(): void {
		$this->assertFalse(Repository::madeWithKey($this->backend, KeyRing::generate()));
	}

	public function testTheSettingsAreSealedAndWrittenOnce(): void {
		$this->assertSame(30, Repository::settingsAt($this->backend, $this->keys)['delete_delay_days']);
		Repository::openWithKey($this->backend, $this->keys)->recordSettings(['delete_delay_days' => 1]);
		$this->assertSame(30, Repository::settingsAt($this->backend, $this->keys)['delete_delay_days']);
		$this->assertSame(['unreadable' => true], Repository::settingsAt($this->backend, KeyRing::generate()), 'another key: there, but not readable');
		unlink($this->dir . '/settings');
		$this->assertSame([], Repository::settingsAt($this->backend, $this->keys), 'none at all: a location from before 1.1.0');
	}

	public function testAlteredSettingsAreNotBelieved(): void {
		$file = $this->dir . '/settings';
		$raw = file_get_contents($file);
		$raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";
		file_put_contents($file, $raw);
		$this->assertSame(['unreadable' => true], Repository::settingsAt($this->backend, $this->keys));
	}

	public function testTheKitWritesALostConfigAgainAndTheBackupsOpen(): void {
		$src = $this->tempDir();
		file_put_contents("$src/a", 'still here');
		$repo = Repository::openWithKey($this->backend, $this->keys);
		$id = $repo->id();
		$snapshot = $repo->backupDirectory($src)['snapshot'];
		unlink($this->dir . '/config');
		$this->assertTrue(Repository::holdsData($this->backend));

		Repository::repairConfig($this->backend, $this->keys, $this->keys->wrap('kit passphrase'));
		$repo = Repository::openWithKey($this->backend, $this->keys);
		$this->assertSame($id, $repo->id());
		$this->assertSame([$snapshot], $repo->listSnapshots());
		$out = $this->tempDir();
		$repo->restore($snapshot, $out);
		$this->assertSame('still here', file_get_contents("$out/a"));
		$this->assertSame($this->keys->exportMasterKey(), KeyRing::unwrapAny(Repository::wrappedKeyAt($this->backend), 'kit passphrase')->exportMasterKey());
	}

	public function testAnotherKeyWritesNoConfig(): void {
		unlink($this->dir . '/config');
		$other = KeyRing::generate();
		try {
			Repository::repairConfig($this->backend, $other, $other->wrap('x'));
			$this->fail('a config was written with another key');
		} catch (\OCA\NgBackup\Repository\RepositoryException $e) {
			$this->assertStringContainsString('another server', $e->getMessage());
		}
		$this->assertFileDoesNotExist($this->dir . '/config');
	}

	public function testAConfigThatIsThereIsNeverRepaired(): void {
		$before = file_get_contents($this->dir . '/config');
		try {
			Repository::repairConfig($this->backend, $this->keys, $this->keys->wrap('x'));
			$this->fail('an existing config was replaced');
		} catch (\OCA\NgBackup\Repository\RepositoryException) {
		}
		$this->assertSame($before, file_get_contents($this->dir . '/config'));
	}

	public function testALocationWithoutSettingsHasNone(): void {
		$other = new LocalBackend($this->tempDir());
		Repository::initWithKey($other, $this->keys, $this->keys->wrap('x'));
		$this->assertSame([], Repository::settingsAt($other, $this->keys));
	}
}
