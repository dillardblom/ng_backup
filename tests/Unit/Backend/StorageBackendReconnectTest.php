<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\StorageBackend;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

/**
 * A Hetzner Storage Box drops an SFTP connection that carried a lot of data and then stayed
 * quiet for a few minutes; files_external keeps using the dead client, on which every file
 * looks missing. The backend builds the storage anew instead.
 */
class StorageBackendReconnectTest extends TestCase {
	/** @var list<ReconnectFakeSftp> */
	private array $connections = [];
	/** New connections come up dead (the location is gone). */
	private bool $deadOnArrival = false;

	private function storage(): IStorage {
		$sftp = new ReconnectFakeSftp();
		$sftp->dropped = $this->deadOnArrival;
		$this->connections[] = $sftp;
		$storage = $this->createMock(SftpLikeStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $class): bool => $class === \OCA\Files_External\Lib\Storage\SFTP::class);
		$storage->method('getConnection')->willReturn($sftp);
		$storage->method('is_dir')->willReturn(true);
		$storage->method('file_exists')->willReturnCallback(fn (string $path): bool => $sftp->request() && $path === 'repo/config');
		$storage->method('fopen')->willReturnCallback(function (string $path) use ($sftp) {
			if (!$sftp->request() || $path !== 'repo/config') {
				return false;
			}
			$fh = fopen('php://memory', 'w+b');
			fwrite($fh, 'config');
			rewind($fh);
			return $fh;
		});
		return $storage;
	}

	private function backend(bool $reconnect = true): StorageBackend {
		return new StorageBackend($this->storage(), 'repo', false, $reconnect ? fn (): IStorage => $this->storage() : null);
	}

	private static function idle(StorageBackend $backend): void {
		(new \ReflectionProperty($backend, 'lastUsed'))->setValue($backend, microtime(true) - StorageBackend::IDLE_CHECK - 1);
	}

	public function testADeadConnectionIsReplacedAfterAPause(): void {
		$backend = $this->backend();
		$this->connections[0]->dropped = true;
		self::idle($backend);
		$this->assertTrue($backend->exists('config'));
		$this->assertSame(1, $backend->reconnects);
		$this->assertSame(1, $this->connections[0]->requests, 'only the check went over the old connection');
	}

	public function testAPauseOnALiveConnectionCostsOneCheck(): void {
		$backend = $this->backend();
		self::idle($backend);
		$this->assertTrue($backend->exists('config'));
		$this->assertSame(0, $backend->reconnects);
		$this->assertSame(2, $this->connections[0]->requests);
		$this->assertTrue($backend->exists('config'));
		$this->assertSame(3, $this->connections[0]->requests, 'no check without a pause');
	}

	public function testAFailureOnAConnectionThatJustDiedIsRepeated(): void {
		$backend = $this->backend();
		$this->connections[0]->dropped = true; // without a pause: not checked first
		$this->assertTrue($backend->exists('config'));
		$fh = $backend->get('config');
		$this->assertSame('config', stream_get_contents($fh));
		$this->assertSame(1, $backend->reconnects);
	}

	public function testAMissingFileOnALiveConnectionIsJustMissing(): void {
		$backend = $this->backend();
		$this->assertFalse($backend->exists('nothing'));
		try {
			$backend->get('nothing');
			$this->fail('missing');
		} catch (BackendException $e) {
			$this->assertStringContainsString('Not found', $e->getMessage());
		}
		$this->assertSame(0, $backend->reconnects);
		$this->assertCount(1, $this->connections);
	}

	public function testANewConnectionThatFailsToo(): void {
		$backend = $this->backend();
		$this->connections[0]->dropped = true;
		$backend->exists('config'); // reconnects
		$this->connections[1]->dropped = true;
		$this->deadOnArrival = true;
		self::idle($backend);
		try {
			$backend->get('config');
			$this->fail('no connection');
		} catch (BackendException) {
		}
		$this->assertSame(3, $backend->reconnects, 'once after the pause, once after the failure, no more');
	}

	public function testWithoutAWayToReconnectNothingChanges(): void {
		$backend = $this->backend(false);
		$this->connections[0]->dropped = true;
		self::idle($backend);
		$this->assertFalse($backend->exists('config'));
		$this->assertCount(1, $this->connections);
	}
}

interface SftpLikeStorage extends IStorage {
	public function getConnection(): object;
}

/** The public phpseclib calls the backend uses to tell a dead connection. */
final class ReconnectFakeSftp {
	public bool $dropped = false;
	private bool $connected = true;
	public int $requests = 0;

	public function isConnected(): bool {
		return $this->connected;
	}

	public function clearStatCache(): void {
	}

	public function stat(string $path): array|false {
		return $this->request() ? ['type' => 2] : false;
	}

	/** A request: false (and from then on disconnected) when the server has dropped the connection. */
	public function request(): bool {
		$this->requests++;
		if ($this->dropped) {
			$this->connected = false;
		}
		return $this->connected;
	}
}
