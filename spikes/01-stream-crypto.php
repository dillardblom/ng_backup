<?php

declare(strict_types=1);

// Spike A: streaming encryption with bounded memory.
// Usage: php -d memory_limit=64M spikes/01-stream-crypto.php [size-in-MiB] [workdir]

require __DIR__ . '/bootstrap.php';

use OCA\NgBackup\Crypto\CryptoException;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Crypto\StreamCipher;

/** Write-only stream that hashes everything written to it (so no plaintext copy hits the disk). */
final class HashSink {
	public static \HashContext $ctx;
	public static int $bytes = 0;
	public $context;
	public function stream_open(): bool { self::$ctx = hash_init('sha256'); self::$bytes = 0; return true; }
	public function stream_write(string $d): int { hash_update(self::$ctx, $d); self::$bytes += strlen($d); return strlen($d); }
	public function stream_close(): void {}
}
stream_wrapper_register('hashsink', HashSink::class);

$sizeMiB = (int)($argv[1] ?? 1024);
$work = rtrim($argv[2] ?? sys_get_temp_dir(), '/');
$plainFile = "$work/ngb-plain.bin";
$encFile = "$work/ngb-enc.bin";

$ok = true;
$check = static function (string $name, bool $pass) use (&$ok): void {
	printf("%-58s %s\n", $name, $pass ? 'PASS' : 'FAIL');
	$ok = $ok && $pass;
};

// 1. Round trip of a large file.
$t = microtime(true);
$fh = fopen($plainFile, 'wb');
$h = hash_init('sha256');
for ($i = 0; $i < $sizeMiB; $i++) {
	$block = random_bytes(1048576);
	hash_update($h, $block);
	fwrite($fh, $block);
}
fclose($fh);
$plainHash = hash_final($h);
printf("generated %d MiB in %.1fs\n", $sizeMiB, microtime(true) - $t);

$keys = KeyRing::generate();
$cipher = new StreamCipher($keys);

memory_reset_peak_usage();
$t = microtime(true);
$in = fopen($plainFile, 'rb');
$out = fopen($encFile, 'wb');
$encBytes = $cipher->encrypt($in, $out, 'blob:test');
fclose($in);
fclose($out);
$encSecs = microtime(true) - $t;
$encPeak = memory_get_peak_usage();

memory_reset_peak_usage();
$t = microtime(true);
$in = fopen($encFile, 'rb');
$out = fopen('hashsink://x', 'wb');
$cipher->decrypt($in, $out, 'blob:test');
fclose($in);
fclose($out);
$decSecs = microtime(true) - $t;
$decPeak = memory_get_peak_usage();
$roundHash = hash_final(HashSink::$ctx);

printf("encrypt: %.1fs (%.0f MiB/s), peak %.1f MiB, overhead %.3f%%\n", $encSecs, $sizeMiB / $encSecs, $encPeak / 1048576,
	($encBytes - $sizeMiB * 1048576) / ($sizeMiB * 1048576) * 100);
printf("decrypt: %.1fs (%.0f MiB/s), peak %.1f MiB\n", $decSecs, $sizeMiB / $decSecs, $decPeak / 1048576);
$check('round trip: sha256 identical', $roundHash === $plainHash);
$check('peak memory below 8 MiB (independent of size)', max($encPeak, $decPeak) < 8 * 1048576);
unlink($plainFile);

// 2. Failure cases on a small object.
$small = $cipher->encryptString(str_repeat('abc', 100000), 'ad');
$expectFail = static function (callable $fn): bool {
	try { $fn(); return false; } catch (CryptoException) { return true; }
};
$check('tampered byte is rejected', $expectFail(function () use ($cipher, $small) {
	$bad = $small; $bad[100] = chr(ord($bad[100]) ^ 1); $cipher->decryptString($bad, 'ad');
}));
$check('truncated object is rejected', $expectFail(fn () => $cipher->decryptString(substr($small, 0, -30), 'ad')));
$check('object cut at a frame boundary is rejected', $expectFail(function () use ($cipher) {
	$two = $cipher->encryptString(str_repeat('x', StreamCipher::FRAME_SIZE + 10), 'ad');
	$firstFrameEnd = 4 + 24 + 4 + StreamCipher::FRAME_SIZE + 17;
	$cipher->decryptString(substr($two, 0, $firstFrameEnd), 'ad');
}));
$check('trailing garbage is rejected', $expectFail(fn () => $cipher->decryptString($small . 'x', 'ad')));
$check('wrong associated data is rejected', $expectFail(fn () => $cipher->decryptString($small, 'other')));
$check('wrong key is rejected', $expectFail(fn () => (new StreamCipher(KeyRing::generate()))->decryptString($small, 'ad')));
$check('empty plaintext round trip', $cipher->decryptString($cipher->encryptString(''), '') === '');

// 3. Wrapping the master key with a passphrase.
$t = microtime(true);
$wrapped = $keys->wrap('correct horse battery staple');
$unwrapped = KeyRing::unwrap($wrapped, 'correct horse battery staple');
printf("wrap+unwrap (Argon2id moderate): %.1fs\n", microtime(true) - $t);
$check('unwrapped key decrypts', (new StreamCipher($unwrapped))->decryptString($small, 'ad') === str_repeat('abc', 100000));
$check('wrong passphrase is rejected', $expectFail(fn () => KeyRing::unwrap($wrapped, 'wrong')));
$check('same blob id from both keyrings', $keys->blobId('hello') === $unwrapped->blobId('hello'));
$check('blob id differs per repository', $keys->blobId('hello') !== KeyRing::generate()->blobId('hello'));

unlink($encFile);
echo $ok ? "ALL PASS\n" : "FAILURES\n";
exit($ok ? 0 : 1);
