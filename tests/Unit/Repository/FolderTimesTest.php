<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Job\BackupRun;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/** Folders with something in them get their original modification time back on a restore. */
class FolderTimesTest extends TestCase {
	use TempDirTrait;

	private const OLD = 1500000000;
	private const OLDER = 1400000000;

	private LocalBackend $backend;
	private KeyRing $keys;
	private string $src;

	protected function setUp(): void {
		$this->backend = new LocalBackend($this->tempDir());
		$this->keys = KeyRing::generate();
		Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$this->src = $this->tempDir();
		mkdir("$this->src/data/alice/files/Old/Deep", 0700, true);
		mkdir("$this->src/data/alice/files/2026", 0700, true);
		file_put_contents("$this->src/data/alice/files/Old/Deep/a.txt", 'a');
		file_put_contents("$this->src/data/alice/files/Old/b.txt", 'b');
		file_put_contents("$this->src/data/alice/files/2026/c.txt", 'c');
		touch("$this->src/data/alice/files/Old/Deep", self::OLDER);
		touch("$this->src/data/alice/files/Old", self::OLD);
		touch("$this->src/data/alice/files/2026", self::OLD);
	}

	private function repo(): Repository {
		return Repository::openWithKey($this->backend, $this->keys);
	}

	private function backup(float $seconds = 30.0): string {
		$state = BackupRun::start(['data' => "$this->src/data"]);
		for ($i = 0; $i < 500 && $state['phase'] !== 'commit'; $i++) {
			$state = json_decode(json_encode(BackupRun::step($this->repo(), $state, microtime(true) + $seconds)), true);
		}
		$this->assertSame('commit', $state['phase']);
		$this->repo()->recordSnapshot($state['snapshot']);
		return $state['snapshot'];
	}

	private function time(string $path): int {
		clearstatcache();
		return (int)filemtime($path);
	}

	public function testEachFolderIsRecordedOnceAlsoInSmallSteps(): void {
		$expected = ['data', 'data/alice', 'data/alice/files', 'data/alice/files/2026', 'data/alice/files/Old', 'data/alice/files/Old/Deep'];
		$this->assertSame($expected, array_column($this->repo()->folderTimes($this->backup()), 0));
		$this->assertSame($expected, array_column($this->repo()->folderTimes($this->backup(0.0)), 0), 'a step per entry');
	}

	public function testNoEmptyFolderIsAmongThem(): void {
		mkdir("$this->src/data/alice/files/Hollow");
		$snapshot = $this->backup();
		$this->assertNotContains('data/alice/files/Hollow', array_column($this->repo()->folderTimes($snapshot), 0));
		$this->assertContains('data/alice/files/Hollow', array_column($this->repo()->emptyDirs($snapshot), 0));
	}

	public function testARestoreBringsThemBack(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->repo()->restore($snapshot, $target);
		$this->assertSame(self::OLD, $this->time("$target/data/alice/files/Old"));
		$this->assertSame(self::OLDER, $this->time("$target/data/alice/files/Old/Deep"));
		$this->assertSame(self::OLD, $this->time("$target/data/alice/files/2026"));
	}

	public function testARestoreThatGoesOnAfterAStopToo(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->repo()->restore($snapshot, $target);
		touch("$target/data/alice/files/Old", 1800000000);
		$this->repo()->restore($snapshot, $target, keepUnchanged: true);
		$this->assertSame(self::OLD, $this->time("$target/data/alice/files/Old"));
	}

	public function testAPrefixLeavesTheTargetAndOthersAlone(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->repo()->restore($snapshot, $target, 'data/alice/files/Old/', true);
		$this->assertSame(self::OLDER, $this->time("$target/Deep"));
		$this->assertNotSame(self::OLD, $this->time($target), 'the directory that was chosen is not ours to change');
		$this->assertDirectoryDoesNotExist("$target/2026");
	}

	public function testALinkOutOfTheTargetIsRefused(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$outside = $this->tempDir();
		touch($outside, 1800000000);
		mkdir("$target/data/alice/files", 0700, true);
		symlink($outside, "$target/data/alice/files/Old");
		$this->expectException(\OCA\NgBackup\Repository\RestoreTargetException::class);
		$this->repo()->restore($snapshot, $target);
	}

	public function testAFolderWithABadNameIsLeftOutAndWhatIsInItReported(): void {
		mkdir("$this->src/data/odd\\folder");
		file_put_contents("$this->src/data/odd\\folder/x", 'x');
		$state = BackupRun::start(['data' => "$this->src/data"]);
		for ($i = 0; $i < 50 && $state['phase'] !== 'commit'; $i++) {
			$state = json_decode(json_encode(BackupRun::step($this->repo(), $state, 0.0), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		}
		$this->assertSame('commit', $state['phase']);
		$this->assertSame(1, $state['stats']['badNames']);
		$this->repo()->recordSnapshot($state['snapshot']);
		$this->assertNotContains("data/odd\\folder", array_column($this->repo()->folderTimes($state['snapshot']), 0));
	}

	public function testASnapshotWithoutThemHasNone(): void {
		$snapshot = $this->repo()->backupDirectory($this->src)['snapshot'];
		$this->assertSame([], $this->repo()->folderTimes($snapshot));
	}
}
