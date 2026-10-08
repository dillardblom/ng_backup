<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\StorageBackend;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

/** On a remote storage every is_dir() is a round trip: listing index/ must not cost one per pack. */
class StorageBackendListTest extends TestCase {
	use TempDirTrait;

	public function testListFilesChecksOnlyTheFolderItself(): void {
		$dir = $this->tempDir();
		foreach (['b1', 'a2', 'c3.part-0123abcd'] as $name) {
			touch("$dir/$name");
		}
		$isDirCalls = 0;
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('is_dir')->willReturnCallback(function (string $path) use (&$isDirCalls): bool {
			$isDirCalls++;
			return $path === 'repo' || $path === 'repo/index';
		});
		$storage->method('opendir')->willReturnCallback(fn (string $path) => $path === 'repo/index' ? opendir($dir) : false);

		$backend = new StorageBackend($storage, 'repo');
		$isDirCalls = 0; // the constructor checks the base folder
		$this->assertSame(['index/a2', 'index/b1'], $backend->listFiles('index'));
		$this->assertSame(1, $isDirCalls);

		$isDirCalls = 0;
		$this->assertSame(['index/a2', 'index/b1'], $backend->list('index'));
		$this->assertSame(4, $isDirCalls, 'list() checks every entry: the reason listFiles() exists');
	}

	public function testListFilesOfAMissingFolderIsEmpty(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('is_dir')->willReturnCallback(fn (string $path): bool => $path === 'repo');
		$storage->expects($this->never())->method('opendir');
		$this->assertSame([], (new StorageBackend($storage, 'repo'))->listFiles('snapshots'));
	}

	public function testAnUnreadableFolderIsAnErrorNotEmpty(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('is_dir')->willReturn(true);
		$storage->method('opendir')->willReturn(false);
		$this->expectException(BackendException::class);
		(new StorageBackend($storage, 'repo'))->listFiles('catalog');
	}
}
