<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/** A pin and a note on a snapshot live in the catalog on the location, so a new server sees them too. */
class SnapshotMarksTest extends TestCase {
	use TempDirTrait;

	private LocalBackend $backend;
	private KeyRing $keys;
	private string $snapshot;

	protected function setUp(): void {
		$this->backend = new LocalBackend($this->tempDir());
		$this->keys = KeyRing::generate();
		$src = $this->tempDir();
		file_put_contents("$src/a", 'x');
		$this->snapshot = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'))->backupDirectory($src)['snapshot'];
	}

	private function repo(): Repository {
		return Repository::openWithKey($this->backend, $this->keys);
	}

	public function testTheyAreThereAfterOpeningAgain(): void {
		$this->repo()->setPinned($this->snapshot, true);
		$this->repo()->setSnapshotNote($this->snapshot, 'before the upgrade');
		$this->assertSame([$this->snapshot], $this->repo()->pinnedSnapshots());
		$this->assertSame([$this->snapshot => 'before the upgrade'], $this->repo()->snapshotNotes());
	}

	public function testTheyCanBeTakenOffAgain(): void {
		$this->repo()->setPinned($this->snapshot, true);
		$this->repo()->setSnapshotNote($this->snapshot, 'x');
		$this->repo()->setPinned($this->snapshot, false);
		$this->repo()->setSnapshotNote($this->snapshot, '');
		$this->assertSame([], $this->repo()->pinnedSnapshots());
		$this->assertSame([], $this->repo()->snapshotNotes());
	}

	public function testNoChangeWritesNoCatalog(): void {
		$repo = $this->repo();
		$repo->setPinned($this->snapshot, true);
		$generation = $repo->catalogGeneration();
		$repo->setPinned($this->snapshot, true);
		$repo->setSnapshotNote($this->snapshot, '');
		$this->assertSame($generation, $repo->catalogGeneration());
	}

	public function testASnapshotThatIsNotThereIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->repo()->setPinned('0123456789abcdef0123456789abcdef', true);
	}

	public function testATrashedSnapshotLosesItsPinAndKeepsItsNote(): void {
		$repo = $this->repo();
		$repo->setPinned($this->snapshot, true);
		$repo->setSnapshotNote($this->snapshot, 'kept');
		$repo->forget($this->snapshot);
		$this->assertSame([], $this->repo()->pinnedSnapshots());
		$this->assertSame([$this->snapshot => 'kept'], $this->repo()->snapshotNotes());

		$repo = $this->repo();
		$repo->untrash($this->snapshot);
		$this->assertSame([], $this->repo()->pinnedSnapshots(), 'back in the list, as a normal one');
		$this->assertSame([$this->snapshot => 'kept'], $this->repo()->snapshotNotes());
	}

	public function testThePurgeTakesTheNoteAlong(): void {
		$repo = $this->repo();
		$repo->setSnapshotNote($this->snapshot, 'kept');
		$repo->forget($this->snapshot);
		$repo->purgeTrash(0);
		$this->assertSame([], $this->repo()->snapshotNotes());
	}

	public function testACatalogFromBeforeThisVersionReadsAsNone(): void {
		$this->assertSame([], $this->repo()->pinnedSnapshots());
		$this->assertSame([], $this->repo()->snapshotNotes());
	}
}
