<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/**
 * SSH host keys of an SFTP location (#34), as SHA-256 fingerprints in OpenSSH's notation:
 * "SHA256:" and the base64 of the hash without padding, what `ssh-keygen -l` and `ssh` print.
 *
 * files_external checks a host key only against the logged-in user's own list, so occ and cron
 * accept any key (nextcloud/server#14108). A location can therefore hold the fingerprints it
 * expects; PinnedSftp checks them before login().
 *
 * scan() reads a server's host keys itself, with the start of an SSH key exchange and nothing
 * after it: no login, no encryption. phpseclib cannot be used for it: phpseclib 2 (up to
 * Nextcloud 34) only knows RSA and DSA keys, so on those versions it would never show the
 * Ed25519 key that Nextcloud 35 (phpseclib 3) gets from the same server. Pinning only what the
 * running version sees would break the location at the next Nextcloud upgrade.
 * The signature over the exchange is not checked: a server in the middle has a valid key and
 * signature of its own, so it would prove nothing. What the admin pins is what the admin saw.
 */
final class HostKeys {
	/** Location option with the pinned fingerprints (a list); never passed on to files_external. */
	public const OPTION = 'host_key_fingerprints';

	/** One connection per family: the server shows the key of the first algorithm both sides have. */
	private const FAMILIES = [
		'ssh-ed25519' => ['ssh-ed25519'],
		'ecdsa-sha2-nistp256' => ['ecdsa-sha2-nistp256'],
		'ecdsa-sha2-nistp384' => ['ecdsa-sha2-nistp384'],
		'ecdsa-sha2-nistp521' => ['ecdsa-sha2-nistp521'],
		'ssh-rsa' => ['rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'],
		'ssh-dss' => ['ssh-dss'],
	];

	/** Key exchanges offered, all of which only need a public value from this side, never its secret. */
	private const KEX = ['curve25519-sha256', 'curve25519-sha256@libssh.org', 'ecdh-sha2-nistp256',
		'diffie-hellman-group14-sha256', 'diffie-hellman-group16-sha512', 'diffie-hellman-group14-sha1'];
	/** Bytes of the prime of these Diffie-Hellman groups (RFC 3526); all of them start with 64 one bits. */
	private const DH_BYTES = ['diffie-hellman-group14-sha256' => 256, 'diffie-hellman-group16-sha512' => 512, 'diffie-hellman-group14-sha1' => 256];

	private const FINGERPRINT = '/^SHA256:[A-Za-z0-9+\/]{43}$/';
	private const KEY_LINE = '/(?:^|\s)((?:ssh|ecdsa|sk)-[A-Za-z0-9@.-]+|rsa-sha2-(?:256|512))\s+([A-Za-z0-9+\/]{20,}={0,2})(?=\s|$)/m';

	public static function fingerprint(string $blob): string {
		return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
	}

	/**
	 * The fingerprint of a public key written as "<type> <base64>[ comment]": phpseclib's
	 * getServerPublicHostKey() (whose type may be the signature, e.g. "rsa-sha2-256" for an RSA
	 * key), a known_hosts or ssh-keyscan line, a .pub file. Null when it is not such a key.
	 */
	public static function fingerprintOfKey(string $line): ?string {
		if (!preg_match(self::KEY_LINE, $line, $m)) {
			return null;
		}
		$blob = base64_decode($m[2], true);
		return $blob !== false && self::blobType($blob) !== null ? self::fingerprint($blob) : null;
	}

	/** The key type a key blob names first, or null when the bytes are no key blob. */
	private static function blobType(string $blob): ?string {
		if (strlen($blob) < 8) {
			return null;
		}
		$len = self::uint32($blob);
		$type = substr($blob, 4, $len);
		return $len <= 64 && strlen($blob) > 4 + $len && preg_match('/^[a-z0-9@.-]+$/', $type) ? $type : null;
	}

	/**
	 * Fingerprints from what an admin pastes or passes: fingerprints ("SHA256:..."), public keys,
	 * ssh-keyscan or known_hosts lines, `ssh-keygen -l` output, several per value.
	 *
	 * @param list<string> $values
	 * @return list<string> without duplicates, in the given order
	 * @throws \InvalidArgumentException for a value without any, or with an MD5 fingerprint
	 */
	public static function parse(array $values): array {
		$out = [];
		foreach ($values as $value) {
			$found = [];
			foreach (preg_split('/\r?\n/', trim($value)) ?: [] as $line) {
				$line = trim($line);
				if ($line === '' || str_starts_with($line, '#')) {
					continue;
				}
				if (preg_match('/\bMD5:|\b([0-9a-f]{2}:){15}[0-9a-f]{2}\b/i', $line)) {
					throw new \InvalidArgumentException('MD5 fingerprints are not accepted: give the SHA256 fingerprint (ssh-keygen -l -E sha256) or the public key');
				}
				$key = self::fingerprintOfKey($line);
				if ($key !== null) {
					$found[] = $key;
					continue;
				}
				foreach (preg_split('/[\s,;]+/', $line) ?: [] as $word) {
					$word = rtrim(trim($word, '()[]"\''), '=');
					if (preg_match(self::FINGERPRINT, $word)) {
						$found[] = $word;
					}
				}
			}
			if ($found === []) {
				throw new \InvalidArgumentException('No SHA256 fingerprint or public key found in "' . mb_strimwidth(trim($value), 0, 60, '…') . '"');
			}
			array_push($out, ...$found);
		}
		return array_values(array_unique($out));
	}

	/**
	 * The fingerprints stored on a location, as a clean list (a broken value counts as none).
	 *
	 * @param array<string, mixed> $options
	 * @return list<string>
	 */
	public static function pinned(array $options): array {
		$value = $options[self::OPTION] ?? [];
		return is_array($value) ? array_values(array_filter($value, fn ($f) => is_string($f) && preg_match(self::FINGERPRINT, $f) === 1)) : [];
	}

	/**
	 * Host and port as files_external's SFTP storage reads them from its options: "host",
	 * "host:port" or "sftp://host:port" in host, a numeric port option before that.
	 *
	 * @param array<string, mixed> $options
	 * @return array{0: string, 1: int}
	 */
	public static function endpoint(array $options): array {
		$input = (string)($options['host'] ?? '');
		$parsed = parse_url(str_contains($input, '://') ? $input : 'http://' . $input);
		$host = is_array($parsed) && isset($parsed['host']) ? $parsed['host'] : $input;
		$port = is_array($parsed) && isset($parsed['port']) ? $parsed['port'] : 22;
		$option = $options['port'] ?? null;
		return [$host, is_numeric($option) ? (int)$option : $port];
	}

	/**
	 * All host keys the server offers, one connection per key type.
	 *
	 * @return list<array{type: string, fingerprint: string}>
	 * @throws BackendException when the server cannot be reached or does not speak SSH
	 */
	public static function scan(string $host, int $port, float $timeout = 10.0): array {
		$offered = null;
		$keys = [];
		foreach (self::FAMILIES as $family => $algorithms) {
			if ($offered !== null && array_intersect($algorithms, $offered) === []) {
				continue;
			}
			$r = self::exchange($host, $port, $algorithms, $timeout);
			$offered ??= $r['offered'];
			if ($r['blob'] !== null) {
				$type = self::blobType($r['blob']) ?? $family;
				$keys[self::fingerprint($r['blob'])] = ['type' => $type, 'fingerprint' => self::fingerprint($r['blob'])];
			}
		}
		if ($keys === []) {
			throw new BackendException("The SSH server at $host:$port offers no host key of a type that is known here");
		}
		return array_values($keys);
	}

	/**
	 * One connection: KEXINIT both ways, then this side's public value; the server answers with
	 * its host key. Closed right after.
	 *
	 * @param list<string> $algorithms host key algorithms to offer
	 * @return array{offered: list<string>, blob: ?string} the server's host key algorithms, and the key (null: none of these)
	 */
	private static function exchange(string $host, int $port, array $algorithms, float $timeout): array {
		$address = 'tcp://' . (str_contains($host, ':') && !str_starts_with($host, '[') ? "[$host]" : $host) . ':' . $port;
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client($address, $errno, $error, $timeout);
		if ($socket === false) {
			throw new BackendException("Cannot connect to $host:$port: " . ($error !== '' ? $error : 'no answer'));
		}
		// One deadline for the whole exchange: a server that answers slowly, line by line, must
		// not keep an admin page waiting for each read's timeout in turn.
		self::$deadline = microtime(true) + $timeout;
		try {
			fwrite($socket, "SSH-2.0-NGBackup_HostKeyCheck\r\n");
			for ($lines = 0; ; $lines++) {
				self::timeLeft($socket);
				$line = fgets($socket, 1024);
				if ($line === false || $lines > 50) {
					throw new BackendException("No SSH server answers at $host:$port");
				}
				if (str_starts_with($line, 'SSH-')) {
					if (!str_starts_with($line, 'SSH-2.0-') && !str_starts_with($line, 'SSH-1.99-')) {
						throw new BackendException("The server at $host:$port does not speak SSH 2");
					}
					break;
				}
			}
			self::send($socket, chr(20) . random_bytes(16) . self::nameList(self::KEX) . self::nameList($algorithms)
				. str_repeat(self::nameList(['aes128-ctr', 'aes256-ctr', 'aes128-gcm@openssh.com', 'aes256-gcm@openssh.com', 'chacha20-poly1305@openssh.com']), 2)
				. str_repeat(self::nameList(['hmac-sha2-256', 'hmac-sha2-512', 'hmac-sha1', 'hmac-sha2-256-etm@openssh.com', 'hmac-sha2-512-etm@openssh.com']), 2)
				. str_repeat(self::nameList(['none']), 2) . str_repeat(self::nameList([]), 2) . "\0" . pack('N', 0));
			$kexinit = self::receive($socket, 20);
			if ($kexinit === null) {
				return ['offered' => [], 'blob' => null];
			}
			$at = 17;
			$serverKex = self::readNameList($kexinit, $at);
			$offered = self::readNameList($kexinit, $at);
			$kex = array_values(array_intersect(self::KEX, $serverKex))[0] ?? null;
			if ($kex === null) {
				throw new BackendException("The SSH server at $host:$port offers no key exchange that is known here");
			}
			if (array_intersect($algorithms, $offered) === []) {
				return ['offered' => $offered, 'blob' => null];
			}
			self::send($socket, chr(30) . self::publicValue($kex));
			$reply = self::receive($socket, 31);
			if ($reply === null) {
				return ['offered' => $offered, 'blob' => null];
			}
			$at = 1;
			return ['offered' => $offered, 'blob' => self::readString($reply, $at)];
		} finally {
			fclose($socket);
		}
	}

	/** This side's public value for the key exchange, encoded as the INIT message wants it. */
	private static function publicValue(string $kex): string {
		if (str_starts_with($kex, 'curve25519')) {
			return self::string(function_exists('sodium_crypto_scalarmult_base') ? sodium_crypto_scalarmult_base(random_bytes(32)) : random_bytes(32));
		}
		if ($kex === 'ecdh-sha2-nistp256') {
			$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
			$ec = $key !== false ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
			if (!is_array($ec)) {
				throw new BackendException('Cannot make an EC key for the host key check');
			}
			return self::string("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
		}
		// Diffie-Hellman: any 1 < e < p-1 will do. A first byte below 0x80 keeps it below p (which
		// starts with 0xff) and positive as an mpint.
		$e = random_bytes(self::DH_BYTES[$kex]);
		$e[0] = chr(random_int(0x10, 0x7f));
		return self::string($e);
	}

	/** @param resource $socket */
	private static function send($socket, string $payload): void {
		$padding = 8 - ((5 + strlen($payload)) % 8);
		if ($padding < 4) {
			$padding += 8;
		}
		$packet = pack('NC', 1 + strlen($payload) + $padding, $padding) . $payload . random_bytes($padding);
		if (fwrite($socket, $packet) !== strlen($packet)) {
			throw new BackendException('The SSH server closed the connection');
		}
	}

	/**
	 * The next packet of the given type; debug and ignore messages are skipped.
	 *
	 * @param resource $socket
	 * @return ?string its payload, or null when the server ends the connection (DISCONNECT)
	 */
	private static function receive($socket, int $type): ?string {
		for ($i = 0; $i < 20; $i++) {
			$length = self::uint32(self::read($socket, 4));
			if ($length < 5 || $length > 262144) {
				throw new BackendException('The SSH server sent a packet that cannot be read');
			}
			$packet = self::read($socket, $length);
			if (ord($packet[0]) > $length - 1) {
				throw new BackendException('The SSH server sent a packet that cannot be read');
			}
			$payload = substr($packet, 1, $length - 1 - ord($packet[0]));
			$got = $payload === '' ? -1 : ord($payload[0]);
			if ($got === $type) {
				return $payload;
			}
			if ($got === 1) {
				return null;
			}
			if (!in_array($got, [2, 4, 3], true)) {
				throw new BackendException("The SSH server sent message $got where $type was expected");
			}
		}
		throw new BackendException('The SSH server sent too many packets');
	}

	private static float $deadline = 0.0;

	/**
	 * The next read may take what is left of the exchange's deadline, no more.
	 *
	 * @param resource $socket
	 */
	private static function timeLeft($socket): void {
		$left = self::$deadline - microtime(true);
		if ($left <= 0) {
			throw new BackendException('The SSH server did not answer in time');
		}
		// at least 1 µs: a timeout of 0 s and 0 µs must not be read as "no timeout"
		stream_set_timeout($socket, (int)$left, max(1, (int)(($left - (int)$left) * 1e6)));
	}

	/** @param resource $socket */
	private static function read($socket, int $bytes): string {
		$data = '';
		while (strlen($data) < $bytes) {
			self::timeLeft($socket);
			$chunk = fread($socket, $bytes - strlen($data));
			if ($chunk === false || $chunk === '') {
				$meta = stream_get_meta_data($socket);
				throw new BackendException($meta['timed_out'] ? 'The SSH server did not answer in time' : 'The SSH server closed the connection');
			}
			$data .= $chunk;
		}
		return $data;
	}

	/** A big-endian 32-bit number from the first four bytes. */
	private static function uint32(string $bytes): int {
		if (strlen($bytes) < 4) {
			throw new BackendException('The SSH server sent a message that cannot be read');
		}
		return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
	}

	private static function string(string $s): string {
		return pack('N', strlen($s)) . $s;
	}

	/** @param list<string> $names */
	private static function nameList(array $names): string {
		return self::string(implode(',', $names));
	}

	private static function readString(string $data, int &$at): string {
		if ($at + 4 > strlen($data)) {
			throw new BackendException('The SSH server sent a message that cannot be read');
		}
		$length = self::uint32(substr($data, $at, 4));
		if ($at + 4 + $length > strlen($data)) {
			throw new BackendException('The SSH server sent a message that cannot be read');
		}
		$s = substr($data, $at + 4, $length);
		$at += 4 + $length;
		return $s;
	}

	/** @return list<string> */
	private static function readNameList(string $data, int &$at): array {
		$s = self::readString($data, $at);
		return $s === '' ? [] : explode(',', $s);
	}
}
