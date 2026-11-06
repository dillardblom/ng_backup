<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Job\BackupRun;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/** Folders without files are recorded in the snapshot's meta record and come back on a restore. */
class EmptyDirsTest extends TestCase {
	use TempDirTrait;

	private LocalBackend $backend;
	private KeyRing $keys;
	private string $src;

	protected function setUp(): void {
		$this->backend = new LocalBackend($this->tempDir());
		$this->keys = KeyRing::generate();
		Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$this->src = $this->tempDir();
		mkdir("$this->src/data/alice/files/Docs", 0700, true);
		mkdir("$this->src/data/alice/files/Hollow/Nested", 0700, true);
		mkdir("$this->src/data/skipped/inner", 0700, true);
		file_put_contents("$this->src/data/alice/files/Docs/note.txt", "hello\n");
		touch("$this->src/data/alice/files/Hollow/Nested", 1700000000);
	}

	private function repo(): Repository {
		return Repository::openWithKey($this->backend, $this->keys);
	}

	/** @param list<string> $exclude */
	private function backup(array $exclude = [], float $seconds = 30.0): string {
		$state = BackupRun::start(['data' => "$this->src/data"], null, '', $exclude);
		for ($i = 0; $i < 500 && $state['phase'] !== 'commit'; $i++) {
			$state = json_decode(json_encode(BackupRun::step($this->repo(), $state, microtime(true) + $seconds)), true);
		}
		$this->assertSame('commit', $state['phase']);
		$this->repo()->recordSnapshot($state['snapshot']);
		return $state['snapshot'];
	}

	public function testOnlyTheDeepestEmptyFolderIsRecordedAndFilesStayAsTheyAre(): void {
		$snapshot = $this->backup();
		$paths = array_column($this->repo()->emptyDirs($snapshot), 0);
		$this->assertSame(['data/alice/files/Hollow/Nested', 'data/skipped/inner'], $paths);
		$this->assertSame(['data/alice/files/Docs/note.txt'], array_column(iterator_to_array($this->repo()->entries($snapshot), false), 'p'));
		$this->assertSame(2, $this->repo()->snapshotMeta($snapshot)['stats']['emptyDirs']);
	}

	public function testAFolderWithOnlyExcludedContentCountsAsEmpty(): void {
		$snapshot = $this->backup(['data/skipped/inner']);
		$this->assertContains('data/skipped', array_column($this->repo()->emptyDirs($snapshot), 0));
		$this->assertNotContains('data/skipped/inner', array_column($this->repo()->emptyDirs($snapshot), 0));
	}

	public function testAResumedWalkRecordsEveryFolderOnce(): void {
		for ($i = 0; $i < 30; $i++) {
			mkdir("$this->src/data/many/d$i", 0700, true);
			file_put_contents("$this->src/data/many/f$i", 'x');
		}
		$snapshot = $this->backup([], 0.0); // a step per entry
		$paths = array_column($this->repo()->emptyDirs($snapshot), 0);
		$this->assertCount(32, $paths);
		$this->assertSame(array_unique($paths), $paths);
	}

	public function testANameASnapshotCannotHoldIsReportedAndTheSnapshotStaysReadable(): void {
		mkdir("$this->src/data/odd\\folder", 0700, true);
		mkdir("$this->src/data/alice/files/line\nbreak", 0700, true);
		file_put_contents("$this->src/data/alice/files/back\\slash.txt", 'x');
		file_put_contents("$this->src/data/alice/files/\xff.txt", 'x');
		$state = BackupRun::start(['data' => "$this->src/data"]);
		for ($i = 0; $i < 50 && $state['phase'] !== 'commit'; $i++) {
			// A step per entry, state through JSON: as separate processes would
			$state = json_decode(json_encode(BackupRun::step($this->repo(), $state, 0.0), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		}
		$this->assertSame('commit', $state['phase']);
		$this->assertSame(4, $state['stats']['unreadable'], 'each reported once, over several steps');
		$this->repo()->recordSnapshot($state['snapshot']);

		$this->assertSame(['data/alice/files/Hollow/Nested', 'data/skipped/inner'], array_column($this->repo()->emptyDirs($state['snapshot']), 0));
		$target = $this->tempDir();
		$this->assertSame(1, $this->repo()->restore($state['snapshot'], $target));
		$this->assertFileExists("$target/data/alice/files/Docs/note.txt");
	}

	public function testAnEmptyFolderThatIsThePrefixItselfIsTheTarget(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->repo()->restore($snapshot, $target, 'data/skipped/inner/', true);
		$this->assertSame([], glob("$target/*"), 'not a folder "inner" inside the target');
	}

	public function testRestoreBringsThemBackWithTheirTime(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->assertSame(1, $this->repo()->restore($snapshot, $target));
		$this->assertDirectoryExists("$target/data/alice/files/Hollow/Nested");
		clearstatcache();
		$this->assertSame(1700000000, filemtime("$target/data/alice/files/Hollow/Nested"));
		$this->assertDirectoryExists("$target/data/skipped/inner");
	}

	public function testRestoreOfAPrefixStripsAndLeavesOthersOut(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->repo()->restore($snapshot, $target, 'data/alice/', true);
		$this->assertDirectoryExists("$target/files/Hollow/Nested");
		$this->assertDirectoryDoesNotExist("$target/skipped");
		$this->assertDirectoryDoesNotExist("$target/inner");
	}

	public function testRunningAgainLeavesAFolderThatIsThereAlone(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$this->repo()->restore($snapshot, $target);
		touch("$target/data/alice/files/Hollow/Nested", 1800000000);
		file_put_contents("$target/data/alice/files/Hollow/Nested/added", 'kept');
		touch("$target/data/alice/files/Hollow/Nested", 1800000000);
		$this->repo()->restore($snapshot, $target, keepUnchanged: true);
		clearstatcache();
		$this->assertSame(1800000000, filemtime("$target/data/alice/files/Hollow/Nested"));
		$this->assertFileExists("$target/data/alice/files/Hollow/Nested/added");
	}

	public function testALinkOutOfTheTargetIsRefused(): void {
		$snapshot = $this->backup();
		$target = $this->tempDir();
		$outside = $this->tempDir();
		mkdir("$target/data/alice/files", 0700, true);
		symlink($outside, "$target/data/alice/files/Hollow");
		try {
			$this->repo()->restore($snapshot, $target);
			$this->fail('restored through a link');
		} catch (\OCA\NgBackup\Repository\RestoreTargetException $e) {
			$this->assertStringContainsString('outside', $e->getMessage());
		}
		$this->assertSame([], glob("$outside/*"));
	}

	public function testASnapshotWithoutThemHasNone(): void {
		$src = $this->tempDir();
		file_put_contents("$src/a", 'x');
		$snapshot = $this->repo()->backupDirectory($src)['snapshot'];
		$this->assertSame([], $this->repo()->emptyDirs($snapshot));
	}
}
