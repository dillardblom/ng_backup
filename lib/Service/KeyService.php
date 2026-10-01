<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\Crypto\KeyRing;
use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * The installation's backup master key.
 *
 * - The server keeps the master key encrypted with Nextcloud's own secret (ICrypto), so
 *   scheduled backups run without anyone typing a passphrase. Whoever fully controls the server
 *   can therefore read the backups; whoever only gets the backup location cannot.
 * - A copy wrapped with the admin's passphrase (Argon2id) goes into every repository config and
 *   into the recovery kit, so backups can be restored on a fresh server with the passphrase.
 * - The admin must confirm having stored the recovery kit before the first backup runs.
 */
class KeyService {
	private const KEY_MASTER = 'master_key';
	private const KEY_WRAPPED = 'master_key_wrapped';
	private const KEY_CONFIRMED = 'recovery_kit_confirmed';
	private const KEY_FINGERPRINT = 'master_key_fingerprint';

	private ?KeyRing $cached = null;

	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
	) {
	}

	public function isInitialized(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_MASTER, '', true) !== '';
	}

	public function initialize(#[\SensitiveParameter] string $passphrase): void {
		if ($this->isInitialized()) {
			throw new \RuntimeException('A backup key already exists');
		}
		if (strlen($passphrase) < 12) {
			throw new \InvalidArgumentException('The passphrase must be at least 12 characters');
		}
		$keys = KeyRing::generate();
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MASTER,
			$this->crypto->encrypt(base64_encode($keys->exportMasterKey())), true, true);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_WRAPPED,
			json_encode($keys->wrap($passphrase), JSON_THROW_ON_ERROR), true, true);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_FINGERPRINT, $this->fingerprintOf($keys), true);
		$this->appConfig->deleteKey(Application::APP_ID, self::KEY_CONFIRMED);
		$this->cached = $keys;
	}

	public function keyRing(): KeyRing {
		if ($this->cached === null) {
			$enc = $this->appConfig->getValueString(Application::APP_ID, self::KEY_MASTER, '', true);
			if ($enc === '') {
				throw new \RuntimeException('No backup key yet: run occ backup:key:init');
			}
			$this->cached = new KeyRing(base64_decode($this->crypto->decrypt($enc), true));
		}
		return $this->cached;
	}

	/** Passphrase-wrapped copy of the master key (for repository configs and the recovery kit). */
	public function wrappedKey(): array {
		return json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_WRAPPED, '{}', true), true, 512, JSON_THROW_ON_ERROR);
	}

	/** Short identifier of the key, shown in the UI and the kit (not secret). */
	public function fingerprint(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_FINGERPRINT, '', true);
	}

	/** @return array{version:int, app:string, fingerprint:string, created:string, wrapped_key:array, instructions:string} */
	public function recoveryKit(): array {
		return [
			'version' => 1,
			'app' => 'NG Backup',
			'fingerprint' => $this->fingerprint(),
			'created' => gmdate('c'),
			'wrapped_key' => $this->wrappedKey(),
			'instructions' => 'Keep this file and your passphrase safe, outside this server. To restore on a new server: install NG Backup, '
				. 'add the backup location, and enter this kit with your passphrase. Without both, the backups cannot be decrypted.',
		];
	}

	public function confirmRecoveryKit(string $uid): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CONFIRMED,
			json_encode(['uid' => $uid, 'time' => time(), 'fingerprint' => $this->fingerprint()], JSON_THROW_ON_ERROR), true);
	}

	/** Confirmation for the current key, or null. */
	public function recoveryKitConfirmation(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_CONFIRMED, '', true);
		$data = $raw === '' ? null : json_decode($raw, true);
		return ($data !== null && ($data['fingerprint'] ?? '') === $this->fingerprint()) ? $data : null;
	}

	private function fingerprintOf(KeyRing $keys): string {
		$id = $keys->blobId('ng_backup key fingerprint');
		return implode('-', str_split(substr($id, 0, 16), 4));
	}
}
