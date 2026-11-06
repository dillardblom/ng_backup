<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Command\Restore;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Repository\RestoreTargetException;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/**
 * What goes wrong where a restore writes to is not damage in the backup: it stops the restore, also
 * with --salvage, and the message does not point at --salvage.
 */
class RestoreTargetTest extends TestCase {
	use TempDirTrait;

	private LocalBackend $backend;
	private KeyRing $keys;
	private string $snapshot;

	protected function setUp(): void {
		$this->backend = new LocalBackend($this->tempDir());
		$this->keys = KeyRing::generate();
		$src = $this->tempDir();
		mkdir("$src/dir");
		file_put_contents("$src/dir/a.bin", random_bytes(6 * 1048576));
		file_put_contents("$src/dir/b.bin", random_bytes(6 * 1048576));
		file_put_contents("$src/dir/c.txt", 'small');
		$this->snapshot = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'))->backupDirectory($src)['snapshot'];
	}

	private function repo(): Repository {
		return Repository::openWithKey($this->backend, $this->keys);
	}

	public function testAShortWriteIsATargetError(): void {
		if (!is_writable('/dev/full')) {
			$this->markTestSkipped('needs /dev/full');
		}
		$out = fopen('/dev/full', 'wb');
		stream_set_write_buffer($out, 0);
		$this->expectException(RestoreTargetException::class);
		Repository::writeAll($out, 'data', 'dir/a.bin');
	}

	public function testATargetErrorDoesNotLookLikeDamage(): void {
		$this->assertFalse(Restore::backupLooksDamaged(new RestoreTargetException('Cannot write')));
	}

	/**
	 * A real full disk: run with NGB_TEST_SMALL_DISK set to an empty, writable tmpfs of a few MiB
	 * (docker run --tmpfs /small:size=8m,uid=...), with and without --salvage.
	 *
	 * @dataProvider salvageOrNot
	 */
	public function testAFullDiskStopsTheRestoreAndLeavesNoTemporary(bool $salvage): void {
		$disk = getenv('NGB_TEST_SMALL_DISK');
		if ($disk === false || $disk === '') {
			$this->markTestSkipped('needs NGB_TEST_SMALL_DISK');
		}
		$target = $disk . '/' . bin2hex(random_bytes(4));
		$skipped = $salvage ? [] : null;
		try {
			$this->repo()->restore($this->snapshot, $target, skipped: $skipped);
			$this->fail('12 MiB fitted on the small disk');
		} catch (RestoreTargetException $e) {
			$this->assertStringContainsString('disk full', $e->getMessage());
		} finally {
			$left = glob("$target/dir/*.ngb-restore-*");
			foreach (glob("$target/dir/*") ?: [] as $f) {
				unlink($f);
			}
			@rmdir("$target/dir");
			@rmdir($target);
		}
		$this->assertSame([], $left);
		if ($salvage) {
			$this->assertSame([], $skipped, 'nothing is listed as damaged');
		}
	}

	/** @return array<string, array{bool}> */
	public static function salvageOrNot(): array {
		return ['without --salvage' => [false], 'with --salvage' => [true]];
	}

	public function testResumeChecksTheFolderBeforeSkipping(): void {
		$target = $this->tempDir();
		$this->repo()->restore($this->snapshot, $target);
		$outside = $this->tempDir();
		rename("$target/dir", "$outside/dir");
		symlink("$outside/dir", "$target/dir");
		$this->expectException(RestoreTargetException::class);
		$this->repo()->restore($this->snapshot, $target, keepUnchanged: true);
	}

	public function testResumeRemovesTemporariesWhereEverythingIsInPlace(): void {
		$target = $this->tempDir();
		$this->repo()->restore($this->snapshot, $target);
		file_put_contents("$target/dir/a.bin.ngb-restore-0123456789ab", 'left by a stopped restore');
		$this->repo()->restore($this->snapshot, $target, keepUnchanged: true);
		$this->assertFileDoesNotExist("$target/dir/a.bin.ngb-restore-0123456789ab");
	}

	public function testHeartbeatRunsForFilesThatAreSkipped(): void {
		$target = $this->tempDir();
		$this->repo()->restore($this->snapshot, $target);
		$beats = 0;
		$this->repo()->restore($this->snapshot, $target, '', false, function () use (&$beats): void {
			$beats++;
		}, keepUnchanged: true);
		$this->assertSame(3, $beats);
	}
}
