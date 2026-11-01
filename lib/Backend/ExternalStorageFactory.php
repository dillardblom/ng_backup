<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

use OCA\Files_External\Lib\StorageConfig;
use OCA\Files_External\Service\BackendService;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

/**
 * Builds a storage object with files_external's own backends and auth mechanisms, the same
 * way files_external's ConfigAdapter does it, but from a configuration that is never saved as
 * a mount. The backup location therefore never appears in Files, for anyone.
 *
 * files_external classes are internal API: everything that touches them is in this class.
 */
final class ExternalStorageFactory {
	public function __construct(
		private BackendService $backends,
	) {
	}

	/**
	 * @return list<array{id:string, name:string, auth:list<array{id:string, name:string, parameters:array}>, parameters:array<string, mixed>}>
	 *         available backends, to build the settings form from
	 */
	public function describeBackends(): array {
		$result = [];
		foreach ($this->backends->getAvailableBackends() as $backend) {
			$auth = [];
			foreach ($this->backends->getAuthMechanismsByScheme(array_keys($backend->getAuthSchemes())) as $mech) {
				// Mechanisms that need the logged-in user's session cannot work for unattended backups.
				if (in_array($mech->getScheme(), ['builtin', 'null'], true) && $mech->getIdentifier() !== 'null::null') {
					continue;
				}
				if (str_starts_with($mech->getIdentifier(), 'password::session') || str_starts_with($mech->getIdentifier(), 'password::logincredentials')
					|| str_starts_with($mech->getIdentifier(), 'password::userprovided') || str_starts_with($mech->getIdentifier(), 'password::global')) {
					continue;
				}
				$auth[] = [
					'id' => $mech->getIdentifier(),
					'name' => $mech->getText(),
					'parameters' => array_map(fn ($p) => $p->jsonSerialize(), $mech->getParameters()),
				];
			}
			$result[] = [
				'id' => $backend->getIdentifier(),
				'name' => $backend->getText(),
				'auth' => $auth,
				'parameters' => array_map(fn ($p) => $p->jsonSerialize(), $backend->getParameters()),
			];
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $options backend and auth options (e.g. host, bucket, key, secret)
	 * @param IUser|null $admin user for auth mechanisms that need one (password/session-based ones are not supported)
	 */
	public function create(string $backendId, string $authId, array $options, ?IUser $admin = null): IStorage {
		$backend = $this->backends->getBackend($backendId);
		$auth = $this->backends->getAuthMechanism($authId);
		if ($backend === null || $auth === null) {
			throw new BackendException("Unknown storage backend or auth mechanism: $backendId / $authId");
		}
		if (!in_array($auth->getScheme(), array_keys($backend->getAuthSchemes()), true)) {
			throw new BackendException("Auth mechanism $authId does not fit backend $backendId");
		}

		if (is_string($options['private_key'] ?? null)) {
			$options['private_key'] = self::normalizePrivateKey($options['private_key']);
		}

		$config = new StorageConfig();
		$config->setBackend($backend);
		$config->setAuthMechanism($auth);
		$config->setBackendOptions($options);
		$config->setMountPoint('/ng_backup'); // never mounted, but some code expects a value

		$auth->manipulateStorageConfig($config, $admin);
		$backend->manipulateStorageConfig($config, $admin);

		$class = $backend->getStorageClass();
		$storage = new $class($config->getBackendOptions());
		$storage = $backend->wrapStorage($storage);
		return $auth->wrapStorage($storage);
	}

	/**
	 * An OpenSSH-format private key with its line breaks restored. Up to Nextcloud 34
	 * (phpseclib 2) a key whose newlines were lost or turned into spaces, e.g. on its way through
	 * a shell or a form, still loaded; Nextcloud 35 (phpseclib 3) refuses it with "Unable to read
	 * key" (#24). The key itself is not changed, so it is fixed when it is used, not when stored.
	 * Other formats (PEM, PKCS#8) still load as they are and are returned unchanged.
	 */
	public static function normalizePrivateKey(string $key): string {
		if (!preg_match('/-----BEGIN OPENSSH PRIVATE KEY-----(.*?)-----END OPENSSH PRIVATE KEY-----/s', $key, $m)) {
			return $key;
		}
		$body = (string)preg_replace('/\s+/', '', $m[1]);
		if (!preg_match('#^[A-Za-z0-9+/]+=*$#', $body)) {
			return $key;
		}
		return "-----BEGIN OPENSSH PRIVATE KEY-----\n" . chunk_split($body, 70, "\n") . "-----END OPENSSH PRIVATE KEY-----\n";
	}
}
