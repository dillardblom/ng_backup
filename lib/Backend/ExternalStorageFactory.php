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
	 * @return list<array{id:string, name:string, auth:list<string>, parameters:array<string, mixed>}>
	 *         available backends, to build the settings form from
	 */
	public function describeBackends(): array {
		$result = [];
		foreach ($this->backends->getAvailableBackends() as $backend) {
			$result[] = [
				'id' => $backend->getIdentifier(),
				'name' => $backend->getText(),
				'auth' => array_keys($backend->getAuthSchemes()),
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
}
