<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\HostKeys;
use PHPUnit\Framework\TestCase;

/** HostKeys: fingerprints in OpenSSH's notation from what an admin pastes or phpseclib reports (#34). */
class HostKeysTest extends TestCase {
	/** A key blob as SSH sends it: its type, then key material (random here). */
	public static function blob(string $type = 'ssh-ed25519'): string {
		return pack('N', strlen($type)) . $type . pack('N', 32) . random_bytes(32);
	}

	public function testFingerprintIsWhatSshKeygenPrints(): void {
		// what `ssh-keygen -lf -` prints for this public key
		$line = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
		$this->assertSame('SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU', HostKeys::fingerprintOfKey($line));
		$this->assertSame('SHA256:' . rtrim(base64_encode(hash('sha256', 'x', true)), '='), HostKeys::fingerprint('x'));
	}

	public function testPhpseclibKeyTextWithSignatureNameHasTheKeysFingerprint(): void {
		// phpseclib names an RSA key after the signature it negotiated; the key blob says ssh-rsa.
		$blob = self::blob('ssh-rsa');
		$this->assertSame(HostKeys::fingerprint($blob), HostKeys::fingerprintOfKey('rsa-sha2-256 ' . base64_encode($blob)));
		$this->assertSame(HostKeys::fingerprint($blob), HostKeys::fingerprintOfKey('ssh-rsa ' . base64_encode($blob) . ' comment'));
	}

	public function testNotAKey(): void {
		$this->assertNull(HostKeys::fingerprintOfKey('hello world'));
		$this->assertNull(HostKeys::fingerprintOfKey('ssh-ed25519 ' . base64_encode(random_bytes(40)) . 'x'));
	}

	public function testParseAcceptsFingerprintsKeysAndKeyscanOutput(): void {
		$ed = self::blob('ssh-ed25519');
		$rsa = self::blob('ssh-rsa');
		$keyscan = "# sftp.example.com:23 SSH-2.0-OpenSSH_9.6\n[sftp.example.com]:23 ssh-ed25519 " . base64_encode($ed)
			. "\n[sftp.example.com]:23 ssh-rsa " . base64_encode($rsa) . "\n";
		$this->assertSame([HostKeys::fingerprint($ed), HostKeys::fingerprint($rsa)], HostKeys::parse([$keyscan]));

		$fp = HostKeys::fingerprint($ed);
		$this->assertSame([$fp], HostKeys::parse([$fp]));
		$this->assertSame([$fp], HostKeys::parse([$fp . '=']), 'with base64 padding');
		$this->assertSame([$fp], HostKeys::parse(['256 ' . $fp . ' sftp.example.com (ED25519)']), 'ssh-keygen -l');
		$this->assertSame([$fp, HostKeys::fingerprint($rsa)], HostKeys::parse([$fp, $keyscan]), 'duplicates once, in order');
	}

	public function testParseRefusesMd5AndNothing(): void {
		foreach (['MD5:12:34:56:78:9a:bc:de:f0:12:34:56:78:9a:bc:de:f0', '12:34:56:78:9a:bc:de:f0:12:34:56:78:9a:bc:de:f0', 'SHA256:tooShort', ''] as $bad) {
			try {
				HostKeys::parse([$bad]);
				$this->fail("accepted: $bad");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testPinnedIgnoresBrokenValues(): void {
		$fp = HostKeys::fingerprint('x');
		$this->assertSame([$fp], HostKeys::pinned([HostKeys::OPTION => [$fp, 'junk', 3]]));
		$this->assertSame([], HostKeys::pinned([HostKeys::OPTION => 'SHA256:x']));
		$this->assertSame([], HostKeys::pinned([]));
	}

	public function testEndpointReadsHostLikeFilesExternal(): void {
		$this->assertSame(['sftp.example.com', 22], HostKeys::endpoint(['host' => 'sftp.example.com']));
		$this->assertSame(['sftp.example.com', 23], HostKeys::endpoint(['host' => 'sftp.example.com:23']));
		$this->assertSame(['sftp.example.com', 23], HostKeys::endpoint(['host' => 'sftp://sftp.example.com:23']));
		$this->assertSame(['sftp.example.com', 2222], HostKeys::endpoint(['host' => 'sftp.example.com:23', 'port' => '2222']));
		$this->assertSame(['sftp.example.com', 23], HostKeys::endpoint(['host' => 'sftp.example.com:23', 'port' => '']));
		$this->assertSame(['[2001:db8::1]', 2200], HostKeys::endpoint(['host' => '[2001:db8::1]:2200']));
	}
}
