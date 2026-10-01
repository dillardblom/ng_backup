<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Crypto;

/**
 * Holds the repository master key and derives the subkeys used for encryption and for the
 * keyed blob hashes. The master key never leaves this object unencrypted, except through
 * wrap(), which seals it with a key derived from the admin's passphrase.
 */
final class KeyRing {
	private const KDF_CONTEXT = 'ngbackup';
	private const SUBKEY_DATA = 1;
	private const SUBKEY_HASH = 2;

	private string $dataKey;
	private string $hashKey;

	public function __construct(
		#[\SensitiveParameter]
		private string $masterKey,
	) {
		if (strlen($masterKey) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
			throw new \InvalidArgumentException('Master key must be ' . SODIUM_CRYPTO_KDF_KEYBYTES . ' bytes');
		}
		$this->dataKey = sodium_crypto_kdf_derive_from_key(
			SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, self::SUBKEY_DATA, self::KDF_CONTEXT, $masterKey);
		$this->hashKey = sodium_crypto_kdf_derive_from_key(
			SODIUM_CRYPTO_GENERICHASH_KEYBYTES, self::SUBKEY_HASH, self::KDF_CONTEXT, $masterKey);
	}

	public static function generate(): self {
		return new self(sodium_crypto_kdf_keygen());
	}

	public function dataKey(): string {
		return $this->dataKey;
	}

	/** Keyed BLAKE2b of plaintext, so blob ids reveal nothing about the content. */
	public function blobId(string $plaintext): string {
		return sodium_bin2hex(sodium_crypto_generichash($plaintext, $this->hashKey, 32));
	}

	/**
	 * Seal the master key with a passphrase (Argon2id). The result is what goes into the
	 * repository config and the recovery kit.
	 *
	 * @return array{v:int, salt:string, ops:int, mem:int, nonce:string, key:string}
	 */
	public function wrap(#[\SensitiveParameter] string $passphrase): array {
		$salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
		$ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
		$mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
		$kek = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$sealed = sodium_crypto_secretbox($this->masterKey, $nonce, $kek);
		sodium_memzero($kek);
		return [
			'v' => 1,
			'salt' => sodium_bin2base64($salt, SODIUM_BASE64_VARIANT_ORIGINAL),
			'ops' => $ops,
			'mem' => $mem,
			'nonce' => sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_ORIGINAL),
			'key' => sodium_bin2base64($sealed, SODIUM_BASE64_VARIANT_ORIGINAL),
		];
	}

	/** @param array{v:int, salt:string, ops:int, mem:int, nonce:string, key:string} $wrapped */
	public static function unwrap(array $wrapped, #[\SensitiveParameter] string $passphrase): self {
		$b64 = static fn (string $s): string => sodium_base642bin($s, SODIUM_BASE64_VARIANT_ORIGINAL);
		$kek = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $b64($wrapped['salt']),
			$wrapped['ops'], $wrapped['mem'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$master = sodium_crypto_secretbox_open($b64($wrapped['key']), $b64($wrapped['nonce']), $kek);
		sodium_memzero($kek);
		if ($master === false) {
			throw new CryptoException('Wrong passphrase or damaged key');
		}
		return new self($master);
	}

	public function __destruct() {
		sodium_memzero($this->masterKey);
		sodium_memzero($this->dataKey);
		sodium_memzero($this->hashKey);
	}
}
