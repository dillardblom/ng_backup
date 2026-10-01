<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Crypto;

/**
 * Streaming authenticated encryption with libsodium secretstream (XChaCha20-Poly1305).
 *
 * Object layout:
 *   "NGB1" | secretstream header (24 bytes) | frame*
 *   frame = uint32 big-endian ciphertext length | ciphertext
 * Each frame holds at most FRAME_SIZE plaintext bytes; the last frame carries TAG_FINAL, so a
 * truncated object fails to decrypt instead of yielding a shorter plaintext.
 *
 * Memory use is bounded by FRAME_SIZE, independent of the object size.
 */
final class StreamCipher {
	public const MAGIC = 'NGB1';
	public const FRAME_SIZE = 65536;
	private const HEADER_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
	private const ABYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

	public function __construct(
		private KeyRing $keys,
	) {
	}

	/**
	 * @param resource $in readable stream (plaintext)
	 * @param resource $out writable stream (ciphertext)
	 * @return int number of ciphertext bytes written
	 */
	public function encrypt($in, $out, string $associatedData = ''): int {
		[$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->keys->dataKey());
		$written = self::write($out, self::MAGIC . $header);

		// Read one frame ahead so the last frame can be tagged FINAL.
		$current = self::readFull($in, self::FRAME_SIZE);
		do {
			$next = $current === '' ? '' : self::readFull($in, self::FRAME_SIZE);
			$tag = $next === ''
				? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
				: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
			$cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, $associatedData, $tag);
			$written += self::write($out, pack('N', strlen($cipher)) . $cipher);
			$current = $next;
		} while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

		sodium_memzero($state);
		return $written;
	}

	/**
	 * @param resource $in readable stream (ciphertext)
	 * @param resource $out writable stream (plaintext)
	 * @return int number of plaintext bytes written
	 * @throws CryptoException on a wrong key, tampering or truncation
	 */
	public function decrypt($in, $out, string $associatedData = ''): int {
		$head = self::readFull($in, strlen(self::MAGIC) + self::HEADER_BYTES);
		if (strlen($head) !== strlen(self::MAGIC) + self::HEADER_BYTES || !str_starts_with($head, self::MAGIC)) {
			throw new CryptoException('Not an NG Backup object');
		}
		$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, strlen(self::MAGIC)), $this->keys->dataKey());

		$written = 0;
		while (true) {
			$len = self::readFull($in, 4);
			if (strlen($len) !== 4) {
				throw new CryptoException('Object is truncated');
			}
			$size = unpack('N', $len)[1];
			if ($size < self::ABYTES || $size > self::FRAME_SIZE + self::ABYTES) {
				throw new CryptoException('Invalid frame size');
			}
			$cipher = self::readFull($in, $size);
			if (strlen($cipher) !== $size) {
				throw new CryptoException('Object is truncated');
			}
			$result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $associatedData);
			if ($result === false) {
				throw new CryptoException('Decryption failed (wrong key or damaged data)');
			}
			[$plain, $tag] = $result;
			$written += self::write($out, $plain);
			if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
				break;
			}
		}
		if (self::readFull($in, 1) !== '') {
			throw new CryptoException('Unexpected data after the final frame');
		}
		sodium_memzero($state);
		return $written;
	}

	/** One-shot helpers for small objects (manifests, index). */
	public function encryptString(string $plain, string $associatedData = ''): string {
		$in = fopen('php://memory', 'w+b');
		fwrite($in, $plain);
		rewind($in);
		$out = fopen('php://memory', 'w+b');
		$this->encrypt($in, $out, $associatedData);
		rewind($out);
		return (string)stream_get_contents($out);
	}

	public function decryptString(string $cipher, string $associatedData = ''): string {
		$in = fopen('php://memory', 'w+b');
		fwrite($in, $cipher);
		rewind($in);
		$out = fopen('php://memory', 'w+b');
		$this->decrypt($in, $out, $associatedData);
		rewind($out);
		return (string)stream_get_contents($out);
	}

	/** fread() may return short reads on network streams; keep reading until $length or EOF. */
	private static function readFull($in, int $length): string {
		$buffer = '';
		while (strlen($buffer) < $length && !feof($in)) {
			$chunk = fread($in, $length - strlen($buffer));
			if ($chunk === false) {
				throw new CryptoException('Read error');
			}
			if ($chunk === '') {
				// Non-blocking stream without data yet, or EOF not yet flagged.
				if (feof($in)) {
					break;
				}
				continue;
			}
			$buffer .= $chunk;
		}
		return $buffer;
	}

	private static function write($out, string $data): int {
		$total = 0;
		$length = strlen($data);
		while ($total < $length) {
			$n = fwrite($out, $total === 0 ? $data : substr($data, $total));
			if ($n === false || $n === 0) {
				throw new CryptoException('Write error');
			}
			$total += $n;
		}
		return $total;
	}
}
