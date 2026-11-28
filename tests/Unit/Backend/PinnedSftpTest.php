<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\Files_External\Lib\Storage\SFTP;
use OCA\NgBackup\Backend\HostKeyMismatchException;
use OCA\NgBackup\Backend\HostKeys;
use OCA\NgBackup\Backend\PinnedSftp;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../stubs/SftpStorage.php';

/**
 * PinnedSftp against files_external's host key check (the stub has the same code): the key is
 * checked before login(), a pinned key passes in whichever form phpseclib writes it, any other
 * key is refused without a login (#34).
 */
class PinnedSftpTest extends TestCase {
	/** @var list<array{key: string, logins: int}> what each connection did */
	private array $connections = [];
	/** Key text the "server" shows, and whether its login succeeds. */
	private string $serverKey = '';
	private bool $loginOk = true;

	protected function setUp(): void {
		PinnedSftp::resetForTests();
		$this->connections = [];
		$test = $this;
		SFTP::$makeClient = static function () use ($test): object {
			$i = count($test->connections);
			$test->connections[] = ['key' => $test->serverKey, 'logins' => 0];
			return new class($test, $i) {
				public function __construct(private PinnedSftpTest $test, private int $i) {
				}
				public function getServerPublicHostKey(): string {
					return $this->test->serverKey();
				}
				public function login(string $user, mixed $auth): bool {
					return $this->test->login($this->i);
				}
				public function disconnect(): void {
				}
			};
		};
	}

	public function serverKey(): string {
		return $this->serverKey;
	}

	public function login(int $i): bool {
		$this->connections[$i]['logins']++;
		return $this->loginOk;
	}

	private function storage(array $pins): PinnedSftp {
		return new PinnedSftp(['host' => 'sftp.example.com', 'port' => '23', 'user' => 'u', 'password' => 'p', HostKeys::OPTION => $pins]);
	}

	public function testPinnedKeyPassesAfterOneRetryThenAtOnce(): void {
		$blob = HostKeysTest::blob('ssh-ed25519');
		$this->serverKey = 'ssh-ed25519 ' . base64_encode($blob);
		$this->storage([HostKeys::fingerprint($blob)])->connect();
		$this->assertSame([['key' => $this->serverKey, 'logins' => 0], ['key' => $this->serverKey, 'logins' => 1]], $this->connections,
			'first connection: refused before login; second: checked and logged in');

		$this->connections = [];
		$this->storage([HostKeys::fingerprint($blob)])->connect();
		$this->assertCount(1, $this->connections, 'the same process needs one connection from then on');
		$this->assertSame(1, $this->connections[0]['logins']);
	}

	public function testRsaKeyUnderItsSignatureNamePasses(): void {
		$blob = HostKeysTest::blob('ssh-rsa');
		$this->serverKey = 'rsa-sha2-256 ' . base64_encode($blob);
		$this->storage([HostKeys::fingerprint(HostKeysTest::blob()), HostKeys::fingerprint($blob)])->connect();
		$this->assertSame(1, end($this->connections)['logins']);
	}

	public function testOtherKeyIsRefusedWithoutLogin(): void {
		$pinned = HostKeys::fingerprint(HostKeysTest::blob());
		$other = HostKeysTest::blob();
		$this->serverKey = 'ssh-ed25519 ' . base64_encode($other);
		try {
			$this->storage([$pinned])->connect();
			$this->fail('connected to a server with another key');
		} catch (HostKeyMismatchException $e) {
			$this->assertSame([$pinned], $e->expected);
			$this->assertSame(HostKeys::fingerprint($other), $e->received);
			$this->assertSame('sftp.example.com:23', $e->endpoint);
			$this->assertStringContainsString($pinned, $e->getMessage());
			$this->assertStringContainsString(HostKeys::fingerprint($other), $e->getMessage());
		}
		$this->assertSame(0, array_sum(array_column($this->connections, 'logins')), 'no password sent');
	}

	public function testKeyThatChangesAfterItPassedIsRefused(): void {
		$blob = HostKeysTest::blob();
		$this->serverKey = 'ssh-ed25519 ' . base64_encode($blob);
		$this->storage([HostKeys::fingerprint($blob)])->connect();
		$this->connections = [];
		$this->serverKey = 'ssh-ed25519 ' . base64_encode(HostKeysTest::blob());
		$this->expectException(HostKeyMismatchException::class);
		try {
			$this->storage([HostKeys::fingerprint($blob)])->connect();
		} finally {
			$this->assertSame(0, array_sum(array_column($this->connections, 'logins')));
		}
	}

	public function testLoginFailureWithTheRightKeyIsNotAMismatch(): void {
		$blob = HostKeysTest::blob();
		$this->serverKey = 'ssh-ed25519 ' . base64_encode($blob);
		$this->loginOk = false;
		try {
			$this->storage([HostKeys::fingerprint($blob)])->connect();
			$this->fail('logged in');
		} catch (HostKeyMismatchException) {
			$this->fail('a failed login reported as another host key');
		} catch (\Exception $e) {
			$this->assertSame('Login failed', $e->getMessage());
		}
		$this->assertCount(2, $this->connections, 'one retry, not a loop');
	}

	public function testPinsAreNotPassedOnToFilesExternal(): void {
		$blob = HostKeysTest::blob();
		$this->serverKey = 'ssh-ed25519 ' . base64_encode($blob);
		$storage = $this->storage([HostKeys::fingerprint($blob)]);
		$this->assertSame([HostKeys::fingerprint($blob)], $storage->pins());
	}
}
