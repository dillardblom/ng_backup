<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\StorageBackend;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../stubs/SmbStorage.php';

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
	public function testSmbFoldersAreListedInOneRequest(): void {
		$calls = new \ArrayObject();
		$share = new class($calls) {
			public function __construct(private \ArrayObject $calls) {
			}

			public function dir(string $path): array {
				$this->calls[] = "dir $path";
				return array_map(fn (string $n) => new class($n, $this->calls) {
					public function __construct(private string $n, private \ArrayObject $calls) {
					}

					public function getName(): string {
						return $this->n;
					}

					public function isHidden(): bool {
						$this->calls[] = "attributes {$this->n}";
						return $this->n === 'desktop.ini';
					}
				}, ['b1', 'a2', '.hidden', 'desktop.ini', 'c3.part-0123abcd']);
			}
		};
		$storage = $this->getMockBuilder(\OCA\Files_External\Lib\Storage\SMB::class)->disableOriginalConstructor()->getMock();
		(fn () => $this->share = $share)->call($storage);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('is_dir')->willReturn(true);
		$storage->expects($this->never())->method('opendir');

		$this->assertSame(['index/a2', 'index/b1'], (new StorageBackend($storage, 'repo'))->listFiles('index'));
		$this->assertSame(['dir repo/index', 'attributes desktop.ini', 'attributes c3.part-0123abcd'], $calls->getArrayCopy(),
			'only names a repository does not use cost a request');
	}

	public function testSmbWithAccessControlListsIsListedTheUsualWay(): void {
		$dir = $this->tempDir();
		touch("$dir/a1");
		$storage = $this->getMockBuilder(\OCA\Files_External\Lib\Storage\SMB::class)->disableOriginalConstructor()->getMock();
		(fn () => $this->checkAcl = true)->call($storage);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('is_dir')->willReturn(true);
		$storage->expects($this->once())->method('opendir')->willReturnCallback(fn () => opendir($dir));
		$this->assertSame(['index/a1'], (new StorageBackend($storage, 'repo'))->listFiles('index'));
	}

	public function testSmbFallsBackToTheUsualListing(): void {
		$dir = $this->tempDir();
		touch("$dir/a1");
		$storage = $this->getMockBuilder(\OCA\Files_External\Lib\Storage\SMB::class)->disableOriginalConstructor()->getMock(); // no share
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->method('is_dir')->willReturn(true);
		$storage->method('opendir')->willReturnCallback(fn () => opendir($dir));
		$this->assertSame(['index/a1'], (new StorageBackend($storage, 'repo'))->listFiles('index'));
	}
}
