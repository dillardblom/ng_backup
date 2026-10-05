<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Backend\ExternalStorageFactory;
use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Backend\StorageBackend;
use OCA\NgBackup\Db\Snapshot;
use OCA\NgBackup\Db\SnapshotMapper;
use OCA\NgBackup\Db\Target;
use OCA\NgBackup\Db\TargetMapper;
use OCA\NgBackup\Repository\Repository;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;
use OCP\Server;

/**
 * Backup targets: a files_external backend + auth mechanism + options + base path. The storage
 * object is built only while it is used; nothing is mounted. files_external is loaded for its
 * classes but its enabled/disabled state is left as the admin set it.
 */
final class TargetService {
	public function __construct(
		private TargetMapper $mapper,
		private KeyService $keys,
		private ICrypto $crypto,
		private IAppManager $appManager,
		private \OCA\NgBackup\Db\DbIndexCache $indexCache,
		private \OCA\NgBackup\Db\AppConfigCatalogAnchor $anchor,
		private SnapshotMapper $snapshots,
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
		$target = $this->mapper->insert($target);
		if (!$created) {
			// Disaster recovery: occ backup:list reads this local cache, not the repository
			// directly, so a snapshot made by another installation must be backfilled here too.
			$this->adoptSnapshots($target, $repo);
		}
		return ['target' => $target, 'created' => $created];
	}

	/**
	 * A target's options are encrypted with Nextcloud's own instance secret (ICrypto), not
	 * ng_backup's key, so changing that secret (occ backup:restore:full merging config.php)
	 * would otherwise leave every target permanently undecryptable. Call $applySecretChange
	 * (which must be the only thing that changes the secret) through here instead: every
	 * target's options are decrypted first, then re-encrypted under whatever secret is active
	 * once $applySecretChange returns.
	 */
	public function reencryptOptionsAround(callable $applySecretChange): mixed {
		$plain = [];
		foreach ($this->mapper->findAll() as $target) {
			$plain[$target->getId()] = $this->options($target);
		}
		$result = $applySecretChange();
		foreach ($this->mapper->findAll() as $target) {
			if (!isset($plain[$target->getId()])) {
				continue; // added by $applySecretChange itself; nothing to re-encrypt
			}
			$target->setOptions($this->crypto->encrypt(json_encode($plain[$target->getId()], JSON_THROW_ON_ERROR)));
			$this->mapper->update($target);
		}
		return $result;
	}

	private function adoptSnapshots(Target $target, Repository $repo): void {
		foreach ($repo->listSnapshots() as $snapshotId) {
			try {
				$this->snapshots->findOne($target->getId(), $snapshotId);
				continue; // already known locally
			} catch (DoesNotExistException) {
			}
			// One unreadable/corrupt snapshot must not stop the rest of the location's
			// snapshots from being backfilled, nor abort adding the target itself.
			try {
				$meta = $repo->snapshotMeta($snapshotId);
				$s = new Snapshot();
				$s->setTargetId($target->getId());
				$s->setSnapshotId($snapshotId);
				$s->setKind((string)($meta['kind'] ?? 'full'));
				$s->setLabel(($meta['label'] ?? '') !== '' ? $meta['label'] : null);
				$s->setCreatedAt((int)strtotime((string)($meta['time'] ?? 'now')));
				$s->setFiles((int)($meta['stats']['files'] ?? 0));
				$s->setBytes((int)($meta['stats']['bytes'] ?? 0));
				$this->snapshots->insert($s);
			} catch (\Throwable) {
			}
		}
	}

	/** @return list<Target> */
	public function list(): array {
		return $this->mapper->findAll();
	}

	public function get(string $nameOrId): Target {
		return ctype_digit($nameOrId) ? $this->mapper->find((int)$nameOrId) : $this->mapper->findByName($nameOrId);
	}

	/** Size limit for the data stored on this location (null = none). */
	public function setMaxBytes(Target $target, ?int $maxBytes): Target {
		$target->setMaxBytes($maxBytes);
		return $this->mapper->update($target);
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
		$repo->setMaxBytes($target->getMaxBytes());
		return $repo;
	}

	/** @return array<string, mixed> */
	public function options(Target $target): array {
		return json_decode($this->crypto->decrypt($target->getOptions()), true, 512, JSON_THROW_ON_ERROR);
	}

	private function buildBackend(string $backend, string $auth, array $options, string $basePath): StorageBackend {
		return new StorageBackend($this->factory()->create($backend, $auth, $options), $basePath);
	}

	private ?ExternalStorageFactory $factory = null;

	/**
	 * files_external's connection code, without changing the app's state:
	 * - enabled: it is booted by Nextcloud; use its own BackendService;
	 * - disabled: do NOT boot it (booting registers its mount provider and listeners, which use
	 *   its database tables; on installations where it was never enabled those do not exist).
	 *   Only its classes are loaded, and a private BackendService gets its backends and auth
	 *   mechanisms from its Application class.
	 */
	private function factory(): ExternalStorageFactory {
		if ($this->factory !== null) {
			return $this->factory;
		}
		if ($this->appManager->isEnabledForAnyone('files_external')) {
			$this->appManager->loadApp('files_external');
			return $this->factory = new ExternalStorageFactory(Server::get(\OCA\Files_External\Service\BackendService::class));
		}
		if (!class_exists(\OCA\Files_External\AppInfo\Application::class)) {
			$path = $this->appManager->getAppPath('files_external');
			require_once $path . '/composer/autoload.php';
		}
		$service = new \OCA\Files_External\Service\BackendService(
			Server::get(\OCP\IAppConfig::class), Server::get(\Psr\Log\LoggerInterface::class));
		$app = new \OCA\Files_External\AppInfo\Application();
		$service->registerBackendProvider($app);
		$service->registerAuthMechanismProvider($app);
		return $this->factory = new ExternalStorageFactory($service);
	}
}
