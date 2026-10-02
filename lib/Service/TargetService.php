<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Backend\ExternalStorageFactory;
use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Backend\StorageBackend;
use OCA\NgBackup\Db\Target;
use OCA\NgBackup\Db\TargetMapper;
use OCA\NgBackup\Repository\Repository;
use OCP\App\IAppManager;
use OCP\Security\ICrypto;
use OCP\Server;

/**
 * Backup targets: a files_external backend + auth mechanism + options + base path. The storage
 * object is built only while it is used; nothing is mounted. files_external is loaded for its
 * classes but its enabled/disabled state is left as the admin set it.
 */
class TargetService {
	public function __construct(
		private TargetMapper $mapper,
		private KeyService $keys,
		private ICrypto $crypto,
		private IAppManager $appManager,
		private \OCA\NgBackup\Db\DbIndexCache $indexCache,
		private \OCA\NgBackup\Db\AppConfigCatalogAnchor $anchor,
	) {
	}

	/** @return list<array{id:string, name:string, auth:list<string>, parameters:array}> */
	public function availableBackends(): array {
		return $this->factory()->describeBackends();
	}

	/**
	 * Add a target: connect, create the repository there (or adopt an existing one made with this
	 * installation's key), then save it.
	 *
	 * @param array<string, mixed> $options
	 * @return array{target: Target, created: bool}
	 */
	public function add(string $name, string $backend, string $auth, array $options, string $basePath): array {
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,63}$/', $name)) {
			throw new \InvalidArgumentException('Name: letters, digits, space, _ . - (max 64)');
		}
		try {
			$this->mapper->findByName($name);
			throw new \InvalidArgumentException("A target named '$name' already exists");
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
		}
		$keys = $this->keys->keyRing();
		$storageBackend = $this->buildBackend($backend, $auth, $options, $basePath);
		if ($storageBackend->exists('config')) {
			$repo = Repository::openWithKey($storageBackend, $keys, $this->indexCache, $this->anchor);
			$created = false;
		} else {
			$repo = Repository::initWithKey($storageBackend, $keys, $this->keys->wrappedKey(), $this->anchor);
			$created = true;
		}

		foreach ($this->mapper->findAll() as $existing) {
			if ($existing->getRepositoryId() === $repo->id()) {
				throw new \InvalidArgumentException("This repository is already registered as '{$existing->getName()}'");
			}
		}

		$target = new Target();
		$target->setName($name);
		$target->setBackend($backend);
		$target->setAuth($auth);
		$target->setOptions($this->crypto->encrypt(json_encode($options, JSON_THROW_ON_ERROR)));
		$target->setBasePath(trim($basePath, '/'));
		$target->setRepositoryId($repo->id());
		$target->setCreatedAt(time());
		return ['target' => $this->mapper->insert($target), 'created' => $created];
	}

	/** @return list<Target> */
	public function list(): array {
		return $this->mapper->findAll();
	}

	public function get(string $nameOrId): Target {
		return ctype_digit($nameOrId) ? $this->mapper->find((int)$nameOrId) : $this->mapper->findByName($nameOrId);
	}

	public function setAppendOnly(Target $target, bool $appendOnly): Target {
		$target->setAppendOnly($appendOnly);
		return $this->mapper->update($target);
	}

	/** Remove the target from NG Backup. The backups on the target itself are not touched. */
	public function remove(Target $target): void {
		$this->mapper->delete($target);
	}

	public function backend(Target $target): IBackend {
		return $this->buildBackend($target->getBackend(), $target->getAuth(), $this->options($target), $target->getBasePath());
	}

	public function repository(Target $target): Repository {
		$repo = Repository::openWithKey($this->backend($target), $this->keys->keyRing(), $this->indexCache, $this->anchor);
		if ($target->getRepositoryId() !== null && $repo->id() !== $target->getRepositoryId()) {
			throw new \RuntimeException('The target now contains a different repository than when it was added');
		}
		return $repo;
	}

	/** @return array<string, mixed> */
	public function options(Target $target): array {
		return json_decode($this->crypto->decrypt($target->getOptions()), true, 512, JSON_THROW_ON_ERROR);
	}

	private function buildBackend(string $backend, string $auth, array $options, string $basePath): StorageBackend {
		return new StorageBackend($this->factory()->create($backend, $auth, $options), $basePath);
	}

	private function factory(): ExternalStorageFactory {
		// Load files_external's classes without enabling the app (its state stays as the admin set it).
		if (!class_exists(\OCA\Files_External\Service\BackendService::class)) {
			$this->appManager->loadApp('files_external');
		}
		return new ExternalStorageFactory(Server::get(\OCA\Files_External\Service\BackendService::class));
	}
}
