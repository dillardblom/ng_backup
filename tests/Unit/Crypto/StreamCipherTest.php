<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Crypto;

use OCA\NgBackup\Crypto\CryptoException;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Crypto\StreamCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamCipherTest extends TestCase {
	private StreamCipher $cipher;

	protected function setUp(): void {
		$this->cipher = new StreamCipher(KeyRing::generate());
	}

	public static function sizes(): array {
		$f = StreamCipher::FRAME_SIZE;
		return ['empty' => [0], 'small' => [100], 'one frame' => [$f], 'frame+1' => [$f + 1], 'several' => [3 * $f + 17]];
	}

	#[DataProvider('sizes')]
	public function testRoundTrip(int $size): void {
		$plain = random_bytes(max(1, $size));
		$plain = substr($plain, 0, $size);
		$this->assertSame($plain, $this->cipher->decryptString($this->cipher->encryptString($plain, 'ad'), 'ad'));
	}

	public function testStreamingKeepsMemoryBounded(): void {
		$in = fopen('php://temp', 'w+b');
		for ($i = 0; $i < 32; $i++) {
			fwrite($in, random_bytes(1048576));
		}
		rewind($in);
		$enc = fopen('php://temp', 'w+b');
		memory_reset_peak_usage();
		$base = memory_get_usage();
		$this->cipher->encrypt($in, $enc);
		$this->assertLessThan(4 * 1048576, memory_get_peak_usage() - $base);
		rewind($enc);
		$out = fopen('php://temp', 'w+b');
		$this->assertSame(32 * 1048576, $this->cipher->decrypt($enc, $out));
	}

	public static function corruptions(): array {
		return [
			'flipped byte' => [static function (string $c): string { $c[60] = chr(ord($c[60]) ^ 1); return $c; }],
			'truncated' => [static fn (string $c): string => substr($c, 0, -10)],
			'trailing data' => [static fn (string $c): string => $c . 'x'],
			'cut at frame boundary' => [static fn (string $c): string => substr($c, 0, 4 + 24 + 4 + StreamCipher::FRAME_SIZE + 17)],
			'bad magic' => [static fn (string $c): string => 'XXXX' . substr($c, 4)],
		];
	}

	#[DataProvider('corruptions')]
	public function testCorruptionIsRejected(callable $corrupt): void {
		$cipher = $this->cipher->encryptString(str_repeat('a', StreamCipher::FRAME_SIZE + 500), 'ad');
		$this->expectException(CryptoException::class);
		$this->cipher->decryptString($corrupt($cipher), 'ad');
	}

	public function testWrongAssociatedDataIsRejected(): void {
		$this->expectException(CryptoException::class);
		$this->cipher->decryptString($this->cipher->encryptString('x', 'blob:1'), 'blob:2');
	}

	public function testWrongKeyIsRejected(): void {
		$this->expectException(CryptoException::class);
		(new StreamCipher(KeyRing::generate()))->decryptString($this->cipher->encryptString('x'));
	}
}
