<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/**
 * --salvage: a file whose data is damaged on the location is left out and listed, the rest of the
 * restore goes on. Without it, the restore stops at that file.
 */
class SalvageTest extends TestCase {
	use TempDirTrait;

	private string $root;
	private KeyRing $keys;
	private string $src;
	private string $snapshot;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->keys = KeyRing::generate();
		$this->src = $this->tempDir();
		mkdir("$this->src/docs");
		// Incompressible, 2 MiB each: every file is a blob of its own in one pack
		foreach (['a', 'b', 'c'] as $name) {
			file_put_contents("$this->src/docs/$name.bin", random_bytes(2 * 1048576));
		}
		file_put_contents("$this->src/note.txt", "small\n");
		$repo = Repository::initWithKey(new LocalBackend($this->root), $this->keys, $this->keys->wrap('pw'));
		$this->snapshot = $repo->backupDirectory($this->src)['snapshot'];
	}

	/** Flip bytes in the middle of the (only) pack: inside one of the 2 MiB files. */
	private function damagePack(): void {
		$packs = glob($this->root . '/packs/*/*');
		$this->assertCount(1, $packs);
		$fh = fopen($packs[0], 'r+b');
		$middle = intdiv(filesize($packs[0]), 2);
		fseek($fh, $middle);
		$bytes = fread($fh, 16);
		fseek($fh, $middle);
		fwrite($fh, ~$bytes);
		fclose($fh);
	}

	public function testSalvageLeavesOutTheDamagedFileAndRestoresTheRest(): void {
		$this->damagePack();
		$target = $this->tempDir();
		$skipped = [];

		$restored = Repository::openWithKey(new LocalBackend($this->root), $this->keys)
			->restore($this->snapshot, $target, '', false, null, $skipped);

		$this->assertSame(3, $restored);
		$this->assertCount(1, $skipped);
		$damaged = array_key_first($skipped);
		$this->assertMatchesRegularExpression('#^docs/[abc]\.bin$#', $damaged);
		$this->assertFileDoesNotExist("$target/$damaged");
		foreach (['docs/a.bin', 'docs/b.bin', 'docs/c.bin', 'note.txt'] as $file) {
			if ($file !== $damaged) {
				$this->assertFileEquals("$this->src/$file", "$target/$file");
			}
		}
		$this->assertSame([], glob("$target/docs/*.ngb-restore-*"), 'no temporary left of the damaged file');
	}

	public function testWithoutSalvageTheRestoreStopsAtTheDamagedFile(): void {
		$this->damagePack();

		$this->expectException(\Exception::class);
		Repository::openWithKey(new LocalBackend($this->root), $this->keys)->restore($this->snapshot, $this->tempDir());
	}

	public function testSalvageOfAnUndamagedSnapshotSkipsNothing(): void {
		$target = $this->tempDir();
		$skipped = [];

		$this->assertSame(4, Repository::openWithKey(new LocalBackend($this->root), $this->keys)
			->restore($this->snapshot, $target, '', false, null, $skipped));
		$this->assertSame([], $skipped);
	}
}
