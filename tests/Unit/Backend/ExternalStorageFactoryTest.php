<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\ExternalStorageFactory;
use PHPUnit\Framework\TestCase;

/** ExternalStorageFactory::normalizePrivateKey(): keys that phpseclib 2 read, for phpseclib 3 (#24). */
class ExternalStorageFactoryTest extends TestCase {
	/** An OpenSSH-format key laid out as ssh-keygen writes it; the body is random, not a real key. */
	private static function key(): string {
		$body = base64_encode(random_bytes(1000));
		return "-----BEGIN OPENSSH PRIVATE KEY-----\n" . chunk_split($body, 70, "\n") . "-----END OPENSSH PRIVATE KEY-----\n";
	}

	public function testRestoresLostLineBreaks(): void {
		$key = self::key();
		$this->assertSame($key, ExternalStorageFactory::normalizePrivateKey($key));
		$this->assertSame($key, ExternalStorageFactory::normalizePrivateKey(str_replace("\n", ' ', $key)), 'newlines as spaces');
		$this->assertSame($key, ExternalStorageFactory::normalizePrivateKey(str_replace("\n", '', $key)), 'no newlines');
		$this->assertSame($key, ExternalStorageFactory::normalizePrivateKey(str_replace("\n", "\r\n", $key)), 'CRLF');
		$this->assertSame($key, ExternalStorageFactory::normalizePrivateKey(rtrim($key)), 'no trailing newline');
		$this->assertSame($key, ExternalStorageFactory::normalizePrivateKey(preg_replace('/^/m', '  ', $key)), 'indented');
	}

	public function testLeavesOtherFormatsAlone(): void {
		$pem = "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: DES-EDE3-CBC,FF44E0A94036766C\n\nAAAA\n-----END RSA PRIVATE KEY-----\n";
		$this->assertSame($pem, ExternalStorageFactory::normalizePrivateKey($pem));
		$this->assertSame('not a key', ExternalStorageFactory::normalizePrivateKey('not a key'));
		$odd = "-----BEGIN OPENSSH PRIVATE KEY-----\\nAAAA\\n-----END OPENSSH PRIVATE KEY-----";
		$this->assertSame($odd, ExternalStorageFactory::normalizePrivateKey($odd), 'not base64 inside: unchanged');
	}
}
