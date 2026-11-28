<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

use OCA\Files_External\Lib\Storage\SFTP;

/**
 * files_external's SFTP storage for a location with pinned host keys (#34): the server's host key
 * is checked against the location's fingerprints in every context (web, occ, cron), before
 * files_external sends the password or signs in with the key.
 *
 * files_external makes the check itself, in getConnection(): it compares the server's key with
 * what readHostKeys() returns for the host, before login(). This class returns the key text it
 * expects there. That text depends on the key type and signature the running phpseclib picks
 * (e.g. "rsa-sha2-256 AAAA..." on Nextcloud 32, "ssh-ed25519 AAAA..." on 35 for the same
 * server), so the first connection of a process may get "does not match": connect() then looks
 * at the key that server sent, and when its fingerprint is pinned, connects once more with that
 * text. The second connection is checked the same way before its login, so a server in the
 * middle cannot slip in between.
 *
 * Only getConnection(), readHostKeys() and the client property are used: their signatures are
 * the same from Nextcloud 32 to 35 (getConnection()'s return type is not, so it is not overridden).
 */
final class PinnedSftp extends SFTP {
	/** The exact key text that passed, per server and set of pins: the next connection of this process needs one try. */
	private static array $passed = [];

	private string $checkHost;
	private string $endpoint;
	/** @var list<string> */
	private array $pins;
	private bool $connected = false;

	/** @param array<string, mixed> $parameters files_external's SFTP options plus HostKeys::OPTION */
	public function __construct(array $parameters) {
		$this->pins = HostKeys::pinned($parameters);
		unset($parameters[HostKeys::OPTION]);
		parent::__construct($parameters);
		[$host, $port] = HostKeys::endpoint($parameters);
		$this->checkHost = $host;
		$this->endpoint = $host . ':' . $port;
	}

	/** @return list<string> */
	public function pins(): array {
		return $this->pins;
	}

	private function passedKey(): string {
		return $this->endpoint . '|' . implode(',', $this->pins);
	}

	#[\Override]
	protected function readHostKeys(): array {
		// Something that never matches until a pinned key has passed: files_external then refuses
		// before login() and connect() takes over.
		return [$this->checkHost => self::$passed[$this->passedKey()] ?? 'ng_backup-host-key-not-checked-yet'];
	}

	#[\Override]
	protected function writeHostKeys(array $keys): bool {
		return false;
	}

	/**
	 * Connect and sign in, with the host key checked first. files_external's own calls hide a
	 * failed connection (most return false), so NG Backup calls this before it uses the storage.
	 *
	 * @throws HostKeyMismatchException when the server's key is not one of the pinned ones
	 * @throws \Exception when the connection or login fails
	 */
	public function connect(): void {
		if ($this->connected) {
			return;
		}
		for ($attempt = 0; ; $attempt++) {
			try {
				$this->getConnection();
				$this->connected = true;
				return;
			} catch (\Exception $e) {
				$received = $this->receivedKey();
				$expected = self::$passed[$this->passedKey()] ?? null;
				$this->dropClient();
				if ($received === null) {
					throw $e;
				}
				$fingerprint = HostKeys::fingerprintOfKey($received);
				if ($fingerprint === null || !in_array($fingerprint, $this->pins, true)) {
					unset(self::$passed[$this->passedKey()]);
					throw new HostKeyMismatchException($this->endpoint, $this->pins, $fingerprint ?? '(unreadable key)', $e);
				}
				if ($received === $expected || $attempt > 0) {
					// the key was the right one: login or the connection failed
					throw $e;
				}
				self::$passed[$this->passedKey()] = $received;
			}
		}
	}

	/** The key text the server of the failed connection sent, if it got that far. */
	private function receivedKey(): ?string {
		if ($this->client === null) {
			return null;
		}
		try {
			$key = $this->client->getServerPublicHostKey();
		} catch (\Throwable) {
			return null;
		}
		return is_string($key) && $key !== '' ? $key : null;
	}

	private function dropClient(): void {
		try {
			$this->client?->disconnect();
		} catch (\Throwable) {
		}
		$this->client = null;
	}

	/** For tests: forget which key text passed. */
	public static function resetForTests(): void {
		self::$passed = [];
	}
}
