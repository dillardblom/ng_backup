<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Crypto;

use OCA\NgBackup\Crypto\CryptoException;
use OCA\NgBackup\Crypto\KeyRing;
use PHPUnit\Framework\TestCase;

class KeyRingTest extends TestCase {
	public function testWrapUnwrapKeepsKeys(): void {
		$keys = KeyRing::generate();
		$restored = KeyRing::unwrap($keys->wrap('pass phrase'), 'pass phrase');
		$this->assertSame($keys->blobId('data'), $restored->blobId('data'));
		$this->assertSame($keys->dataKey(), $restored->dataKey());
	}

	public function testWrongPassphrase(): void {
		$wrapped = KeyRing::generate()->wrap('right');
		$this->expectException(CryptoException::class);
		KeyRing::unwrap($wrapped, 'wrong');
	}

	public function testBlobIdsAreKeyed(): void {
		$this->assertNotSame(KeyRing::generate()->blobId('same'), KeyRing::generate()->blobId('same'));
		$k = KeyRing::generate();
		$this->assertSame($k->blobId('same'), $k->blobId('same'));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $k->blobId('x'));
	}

	public function testRejectsShortMasterKey(): void {
		$this->expectException(\InvalidArgumentException::class);
		new KeyRing('short');
	}

	public function testAnySlotOpensTheSameKey(): void {
		$keys = KeyRing::generate();
		$field = ['slots' => [
			['slot' => 1, 'label' => 'Safe', 'key' => $keys->wrap('passphrase in the safe')],
			['slot' => 2, 'label' => 'CTO', 'key' => $keys->wrap('passphrase of the cto')],
			['slot' => 3, 'label' => 'Head of IT', 'key' => $keys->wrap('passphrase head of it')],
		]];
		foreach (['passphrase in the safe', 'passphrase of the cto', 'passphrase head of it'] as $p) {
			$this->assertSame($keys->blobId('x'), KeyRing::unwrapAny($field, $p)->blobId('x'));
		}
		$this->assertSame($keys->blobId('x'), KeyRing::unwrapAny($keys->wrap('legacy single'), 'legacy single')->blobId('x'));
		$this->expectException(CryptoException::class);
		KeyRing::unwrapAny($field, 'nobody knows this');
	}
}
