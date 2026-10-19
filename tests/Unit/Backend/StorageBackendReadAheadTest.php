<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\SftpRangeFetcher;
use OCA\NgBackup\Backend\SftpReadStream;
use OCA\NgBackup\Backend\StorageBackend;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SftpReadStreamTest.php'; // FakeSftpConnection
require_once __DIR__ . '/StorageBackendReconnectTest.php'; // SftpLikeStorage

/** StorageBackend::readAhead(): over SFTP on connections of its own, built with the reconnect function. */
class StorageBackendReadAheadTest extends TestCase {
	use TempDirTrait;

	/** @var list<FakeSftpConnection> */
	private array $connections = [];
	/** @var array<string, string> */
	private array $files = [];
	/** New storages after this many fail to connect. */
	private ?int $connectLimit = null;

	protected function setUp(): void {
		SftpReadStream::reset();
		for ($i = 0; $i < 6; $i++) {
			$this->files["/home/base/repo/packs/p$i"] = random_bytes(1048576 + $i);
		}
	}

	private function storage(): IStorage {
		if ($this->connectLimit !== null && count($this->connections) >= $this->connectLimit) {
			throw new \RuntimeException('Too many connections');
		}
		$sftp = new FakeSftpConnection($this->files);
		$this->connections[] = $sftp;
		$storage = $this->createMock(SftpLikeRootStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $class): bool => $class === \OCA\Files_External\Lib\Storage\SFTP::class);
		$storage->method('getConnection')->willReturn($sftp);
		$storage->method('getRoot')->willReturn('/home/');
		$storage->method('is_dir')->willReturn(true);
		return $storage;
	}

	/** @return list<array{0:string, 1:int, 2:int}> */
	private static function ranges(): array {
		return array_map(fn (int $i): array => ["packs/p$i", 7, 1048576 - 7], range(0, 5));
	}

	public function testReadsAheadOnNewConnectionsOnly(): void {
		$backend = new StorageBackend($this->storage(), 'base/repo', false, fn (): IStorage => $this->storage());
		$backend->readAheadConnections = 4;
		$this->assertTrue($backend->canReadAhead());

		$result = [];
		foreach ($backend->readAhead(self::ranges(), $this->tempDir()) as $i => $fh) {
			$result[$i] = sha1(stream_get_contents($fh));
		}

		$expected = array_map(fn (int $i): string => sha1(substr($this->files["/home/base/repo/packs/p$i"], 7, 1048576 - 7)), range(0, 5));
		$this->assertSame($expected, $result);
		$this->assertCount(1 + 4, $this->connections);
		$this->assertSame(0, $this->connections[0]->sent, 'the storage\'s own connection stays free');
		foreach (array_slice($this->connections, 1) as $c) {
			$this->assertTrue($c->disconnected);
		}
	}

	public function testGoesOnWithTheConnectionsTheServerAllows(): void {
		$this->connectLimit = 3;
		$backend = new StorageBackend($this->storage(), 'base/repo', false, fn (): IStorage => $this->storage());
		$backend->readAheadConnections = 4;

		$count = 0;
		foreach ($backend->readAhead(self::ranges(), $this->tempDir()) as $fh) {
			$this->assertIsResource($fh);
			$count++;
		}

		$this->assertSame(6, $count);
		$this->assertCount(3, $this->connections);
	}

	public function testByDefaultOneConnectionNextToTheStoragesOwn(): void {
		$backend = new StorageBackend($this->storage(), 'base/repo', false, fn (): IStorage => $this->storage());
		foreach ($backend->readAhead(self::ranges(), $this->tempDir()) as $fh) {
			$this->assertIsResource($fh);
		}

		$this->assertSame(1, SftpRangeFetcher::DEFAULT_CONNECTIONS);
		$this->assertCount(2, $this->connections);
	}

	public function testNoNewConnectionMeansNoReadingAhead(): void {
		$this->connectLimit = 1;
		$backend = new StorageBackend($this->storage(), 'base/repo', false, fn (): IStorage => $this->storage());

		$this->assertNull($backend->readAhead(self::ranges(), $this->tempDir()));
	}

	public function testWithoutAWayToConnectAgainItDoesNotReadAhead(): void {
		$backend = new StorageBackend($this->storage(), 'base/repo', false, null);

		$this->assertFalse($backend->canReadAhead());
		$this->assertNull($backend->readAhead(self::ranges(), $this->tempDir()));
	}
}

interface SftpLikeRootStorage extends SftpLikeStorage {
	public function getRoot(): string;
}
