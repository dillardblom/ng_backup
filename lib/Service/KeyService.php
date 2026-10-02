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
	private const KEY_DELETE_DELAY = 'delete_delay_days';
	private const KEY_KIT_VERSION = 'recovery_kit_version';
	public const DEFAULT_DELETE_DELAY = 7;

	private ?KeyRing $cached = null;

	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
	) {
	}

	public function isInitialized(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_MASTER, '', true) !== '';
	}

	/**
	 * @param int $deleteDelayDays how long forgotten snapshots stay in the trash on the location
	 *                             before their data is really deleted. Fixed here, at installation:
	 *                             there is deliberately no way to change it later from NG Backup.
	 */
	public function initialize(#[\SensitiveParameter] string $passphrase, int $deleteDelayDays = self::DEFAULT_DELETE_DELAY, string $label = 'Slot 1'): void {
		if ($deleteDelayDays < 1 || $deleteDelayDays > 365) {
			throw new \InvalidArgumentException('The deletion delay must be between 1 and 365 days');
		}
		if ($this->isInitialized()) {
			throw new \RuntimeException('A backup key already exists');
		}
		if (strlen($passphrase) < 12) {
			throw new \InvalidArgumentException('The passphrase must be at least 12 characters');
		}
		$keys = KeyRing::generate();
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MASTER,
			$this->crypto->encrypt(base64_encode($keys->exportMasterKey())), true, true);
		$this->cached = $keys;
		$this->saveSlots([['slot' => 1, 'label' => self::cleanLabel($label), 'created' => time(), 'key' => $keys->wrap($passphrase)]]);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_FINGERPRINT, $this->fingerprintOf($keys), true);
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_DELETE_DELAY, $deleteDelayDays, true);
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_KIT_VERSION, 1, true);
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

	/** Deletion delay in days, fixed at installation (installations from before this setting get the default once). */
	public function deleteDelayDays(): int {
		$days = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_DELETE_DELAY, 0, true);
		if ($days === 0 && $this->isInitialized()) {
			$days = self::DEFAULT_DELETE_DELAY;
			$this->appConfig->setValueInt(Application::APP_ID, self::KEY_DELETE_DELAY, $days, true);
		}
		return $days;
	}

	public const MAX_SLOTS = 3;

	/** @return list<array{slot:int, label:string, created:int}> passphrase slots, without key material */
	public function slots(): array {
		return array_map(fn ($s) => ['slot' => $s['slot'], 'label' => $s['label'], 'created' => $s['created']], $this->wrappedKey()['slots']);
	}

	/** Add a passphrase slot (e.g. one per person who must be able to restore). Returns the slot number. */
	public function addSlot(#[\SensitiveParameter] string $passphrase, string $label): int {
		self::checkPassphrase($passphrase);
		$slots = $this->wrappedKey()['slots'];
		if (count($slots) >= self::MAX_SLOTS) {
			throw new \InvalidArgumentException('At most ' . self::MAX_SLOTS . ' passphrase slots');
		}
		$used = array_column($slots, 'slot');
		$n = min(array_diff(range(1, self::MAX_SLOTS), $used));
		$slots[] = ['slot' => $n, 'label' => self::cleanLabel($label), 'created' => time(), 'key' => $this->keyRing()->wrap($passphrase)];
		usort($slots, fn ($a, $b) => $a['slot'] <=> $b['slot']);
		$this->saveSlots($slots);
		return $n;
	}

	/** New passphrase for a slot (e.g. its holder left). The master key and all backups stay the same. */
	public function replaceSlot(int $slot, #[\SensitiveParameter] string $passphrase, ?string $label = null): void {
		self::checkPassphrase($passphrase);
		$slots = $this->wrappedKey()['slots'];
		$found = false;
		foreach ($slots as &$s) {
			if ($s['slot'] === $slot) {
				$s['key'] = $this->keyRing()->wrap($passphrase);
				$s['created'] = time();
				if ($label !== null) {
					$s['label'] = self::cleanLabel($label);
				}
				$found = true;
			}
		}
		unset($s);
		if (!$found) {
			throw new \InvalidArgumentException("There is no slot $slot");
		}
		$this->saveSlots($slots);
	}

	public function removeSlot(int $slot): void {
		$slots = array_values(array_filter($this->wrappedKey()['slots'], fn ($s) => $s['slot'] !== $slot));
		if (count($slots) === count($this->wrappedKey()['slots'])) {
			throw new \InvalidArgumentException("There is no slot $slot");
		}
		if ($slots === []) {
			throw new \InvalidArgumentException('The last passphrase slot cannot be removed');
		}
		$this->saveSlots($slots);
	}

	/** Every change to the slots asks for a new recovery kit (and its confirmation). */
	private function saveSlots(array $slots): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_WRAPPED, json_encode(['slots' => $slots], JSON_THROW_ON_ERROR), true, true);
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_KIT_VERSION, $this->kitVersion() + 1, true);
	}

	private static function checkPassphrase(string $passphrase): void {
		if (strlen($passphrase) < 12) {
			throw new \InvalidArgumentException('The passphrase must be at least 12 characters');
		}
	}

	private static function cleanLabel(string $label): string {
		$label = trim(preg_replace('/[\x00-\x1f]/', '', $label) ?? '');
		return mb_substr($label === '' ? 'Slot' : $label, 0, 64);
	}

	public function kitVersion(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_KIT_VERSION, 1, true);
	}

	/** Passphrase-wrapped copy of the master key (for repository configs and the recovery kit). */
	public function wrappedKey(): array {
		$data = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_WRAPPED, '{}', true), true, 512, JSON_THROW_ON_ERROR);
		if (!isset($data['slots'])) {
			$data = ['slots' => $data === [] ? [] : [['slot' => 1, 'label' => 'Slot 1', 'created' => 0, 'key' => $data]]];
		}
		return $data;
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
			'kit_version' => $this->kitVersion(),
			'created' => gmdate('c'),
			'wrapped_key' => $this->wrappedKey(),
			'slots' => $this->slots(),
			'instructions' => 'Keep this file safe, outside this server. Any one of the passphrases of the slots listed here opens it. '
				. 'To restore on a new server: install NG Backup, add the backup location and enter this kit with one passphrase. '
				. 'Without the kit (or the location) and a passphrase, the backups cannot be decrypted.',
		];
	}

	public function confirmRecoveryKit(string $uid): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CONFIRMED,
			json_encode(['uid' => $uid, 'time' => time(), 'fingerprint' => $this->fingerprint(), 'kit_version' => $this->kitVersion()], JSON_THROW_ON_ERROR), true);
	}

	/**
	 * Whether a recovery kit for this key was ever confirmed (any passphrase version). Backups
	 * keep running after a passphrase change, so changing it cannot be used to stop backups; the
	 * missing re-confirmation is reported as an error in the admin overview instead.
	 */
	public function everConfirmed(): bool {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_CONFIRMED, '', true);
		$data = $raw === '' ? null : json_decode($raw, true);
		return $data !== null && ($data['fingerprint'] ?? '') === $this->fingerprint();
	}

	/** Confirmation for the current key and kit version, or null. */
	public function recoveryKitConfirmation(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_CONFIRMED, '', true);
		$data = $raw === '' ? null : json_decode($raw, true);
		return ($data !== null && ($data['fingerprint'] ?? '') === $this->fingerprint() && ($data['kit_version'] ?? 1) === $this->kitVersion()) ? $data : null;
	}

	private function fingerprintOf(KeyRing $keys): string {
		$id = $keys->blobId('ng_backup key fingerprint');
		return implode('-', str_split(substr($id, 0, 16), 4));
	}
}
