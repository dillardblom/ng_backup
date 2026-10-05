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
final class KeyService {
	private const KEY_MASTER = 'master_key';
	private const KEY_WRAPPED = 'master_key_wrapped';
	private const KEY_CONFIRMED = 'recovery_kit_confirmed';
	private const KEY_FINGERPRINT = 'master_key_fingerprint';
	private const KEY_DELETE_DELAY = 'delete_delay_days';
	private const KEY_KIT_VERSION = 'recovery_kit_version';
	private const KEY_KIT_CODE = 'recovery_kit_code';
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

	/**
	 * Disaster recovery on a fresh installation: adopt the master key from a recovery kit
	 * (occ backup:key:kit on the original installation) instead of generating a new one.
	 * Afterwards occ backup:target:add opens the existing repository on the location with this
	 * key, instead of creating a new, empty one.
	 *
	 * @param array<string, mixed> $kit as produced by recoveryKit()
	 * @throws \OCA\NgBackup\Crypto\CryptoException wrong passphrase, or a damaged/foreign kit
	 */
	public function importRecoveryKit(array $kit, #[\SensitiveParameter] string $passphrase): void {
		if ($this->isInitialized()) {
			throw new \RuntimeException('A backup key already exists');
		}
		if (!isset($kit['wrapped_key'])) {
			throw new \InvalidArgumentException('Not a NG Backup recovery kit file');
		}
		$keys = KeyRing::unwrapAny($kit['wrapped_key'], $passphrase);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MASTER,
			$this->crypto->encrypt(base64_encode($keys->exportMasterKey())), true, true);
		$this->cached = $keys;
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_WRAPPED, json_encode($kit['wrapped_key'], JSON_THROW_ON_ERROR), true, true);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_FINGERPRINT, $this->fingerprintOf($keys), true);
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_DELETE_DELAY, self::DEFAULT_DELETE_DELAY, true);
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_KIT_VERSION, (int)($kit['kit_version'] ?? 1), true);
		// Having and successfully using a real kit file is stronger proof than the normal
		// download-and-confirm flow it replaces.
		$this->confirmRecoveryKit('cli-import');
	}

	/**
	 * The master key and the recovery kit code are stored encrypted with Nextcloud's own
	 * instance secret (ICrypto), not with themselves; the wrapped key is not (it is sealed with
	 * the admin's passphrase instead), but Nextcloud's own AppConfig additionally encrypts any
	 * "sensitive" value with the instance secret regardless, underneath whatever we store. So
	 * changing that secret (occ backup:restore:full merging config.php) would otherwise leave
	 * all three permanently undecryptable. Call $applySecretChange (which must be the only thing
	 * that changes the secret) through here instead: all three are decrypted first, then
	 * re-encrypted under whatever secret is active once $applySecretChange returns.
	 *
	 * Not crash-safe: a process kill between the delete and the re-save of one of these keys
	 * would leave it missing rather than merely stale. Disaster recovery is run once, by hand,
	 * right after occ maintenance:install; a dedicated two-phase write is not justified yet for
	 * that narrow a window.
	 */
	public function reencryptForSecretRotation(callable $applySecretChange): mixed {
		$master = $this->appConfig->getValueString(Application::APP_ID, self::KEY_MASTER, '', true);
		$wrapped = $this->appConfig->getValueString(Application::APP_ID, self::KEY_WRAPPED, '', true);
		$kitCodeRaw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_KIT_CODE, '', true);
		$kitCode = $kitCodeRaw !== '' ? json_decode($kitCodeRaw, true, 512, JSON_THROW_ON_ERROR) : null;
		$masterPlain = $master !== '' ? $this->crypto->decrypt($master) : null;
		$kitCodePlain = $kitCode !== null && isset($kitCode['code']) ? $this->crypto->decrypt($kitCode['code']) : null;

		$result = $applySecretChange();
		$this->cached = null; // the master key is about to be re-saved; do not keep the pre-rotation copy

		// Nextcloud's own AppConfig additionally encrypts a "sensitive" value itself (tied to
		// the same secret, underneath our own encrypt() above); setValueString() on an existing
		// key reads the old value first to compare, which would try to decrypt it with the new
		// secret and fail the same way. Delete first so there is nothing to compare against.
		if ($masterPlain !== null) {
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_MASTER);
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_MASTER, $this->crypto->encrypt($masterPlain), true, true);
		}
		if ($wrapped !== '') {
			// Already plaintext JSON here: getValueString() above transparently undid
			// AppConfig's own sensitive-value encryption; there is no encrypt() of our own to
			// redo (wrapping is sealed with the passphrase, not the instance secret).
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_WRAPPED);
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_WRAPPED, $wrapped, true, true);
		}
		if ($kitCodePlain !== null) {
			$kitCode['code'] = $this->crypto->encrypt($kitCodePlain);
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_KIT_CODE);
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_KIT_CODE, json_encode($kitCode, JSON_THROW_ON_ERROR), true, true);
		}
		return $result;
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
			'confirmation_code' => $this->kitCode(),
			'created' => gmdate('c'),
			'wrapped_key' => $this->wrappedKey(),
			'slots' => $this->slots(),
			'instructions' => 'Keep this file safe, outside this server. Any one of the passphrases of the slots listed here opens it. '
				. 'To restore on a new server: install NG Backup, add the backup location and enter this kit with one passphrase. '
				. 'Without the kit (or the location) and a passphrase, the backups cannot be decrypted.',
		];
	}

	/**
	 * Short code printed in the current kit file. Typing it back when confirming proves the
	 * admin actually has the downloaded kit. A new code per kit version; the server only keeps
	 * its own copy encrypted with the instance secret.
	 */
	private function kitCode(): string {
		$stored = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_KIT_CODE, '{}', true), true) ?: [];
		if (($stored['version'] ?? 0) === $this->kitVersion() && isset($stored['code'])) {
			return $this->crypto->decrypt($stored['code']);
		}
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I
		$code = '';
		for ($i = 0; $i < 8; $i++) {
			$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		$code = substr($code, 0, 4) . '-' . substr($code, 4);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_KIT_CODE,
			json_encode(['version' => $this->kitVersion(), 'code' => $this->crypto->encrypt($code)], JSON_THROW_ON_ERROR), true, true);
		return $code;
	}

	public function checkKitCode(string $code): bool {
		$stored = json_decode($this->appConfig->getValueString(Application::APP_ID, self::KEY_KIT_CODE, '{}', true), true) ?: [];
		if (($stored['version'] ?? 0) !== $this->kitVersion() || !isset($stored['code'])) {
			return false; // no kit downloaded for this version yet
		}
		$normalise = static fn (string $c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c) ?? '');
		return hash_equals($normalise($this->crypto->decrypt($stored['code'])), $normalise($code));
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
