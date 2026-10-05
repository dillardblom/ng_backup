<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Crypto\StreamCipher;

/**
 * Chained catalog of a repository's state, against rollback and hidden snapshots.
 *
 * Every change to the set of snapshots or trash entries writes catalog/<generation>: the
 * encrypted list of snapshot and trash ids plus the hash of the previous generation. The server
 * keeps the newest generation it has seen (ICatalogAnchor). On open, the newest catalog on the
 * location must be at least the anchored generation and lead back to it through the chain;
 * snapshots listed in the catalog must exist. Otherwise the location was rolled back or
 * tampered with, and the repository is refused.
 */
final class Catalog {
	private const KEEP = 200; // generations kept on the location (enough to verify long gaps)

	public int $gen = 0;
	/** @var array<string, true> */
	public array $snapshots = [];
	/** @var array<string, true> */
	public array $trash = [];
	/** @var array<string, true> manifest path => true, for a per-user export (users/<uid>/<exportId>) */
	public array $userExports = [];
	public string $hash = '';

	public function __construct(
		private IBackend $backend,
		private StreamCipher $cipher,
		private string $repositoryId,
		private ?ICatalogAnchor $anchor,
	) {
	}

	/** Load and verify the newest catalog; create the first one for repositories that have none. */
	public function load(callable $listSnapshots, callable $listTrash, callable $listUserExports): void {
		$gens = $this->generations();
		if ($gens === []) {
			// First use (new or pre-catalog repository): adopt the current state as generation 1.
			$anchored = $this->anchor?->get($this->repositoryId);
			if ($anchored !== null) {
				throw new RepositoryException('The catalog of this repository has disappeared from the location (possible tampering)');
			}
			$this->snapshots = array_fill_keys($listSnapshots(), true);
			$this->trash = array_fill_keys($listTrash(), true);
			$this->userExports = array_fill_keys($listUserExports(), true);
			$this->write();
			return;
		}
		$head = max($gens);
		[$data, $hash] = $this->read($head);
		$anchored = $this->anchor?->get($this->repositoryId);
		if ($anchored !== null) {
			if ($head < $anchored['gen']) {
				throw new RepositoryException(sprintf('The location shows catalog generation %d, but this server has seen %d: the backups were rolled back', $head, $anchored['gen']));
			}
			// Walk back to the anchored generation, checking every link.
			[$cur, $curHash] = [$data, $hash];
			for ($g = $head; $g > $anchored['gen']; $g--) {
				[$prev, $prevHash] = $this->read($g - 1);
				if (!hash_equals($prevHash, (string)$cur['prev'])) {
					throw new RepositoryException("Catalog chain broken at generation $g (possible tampering)");
				}
				[$cur, $curHash] = [$prev, $prevHash];
			}
			if (!hash_equals($anchored['hash'], $curHash)) {
				throw new RepositoryException('The catalog does not match what this server saw before (possible tampering)');
			}
		}
		$this->gen = $head;
		$this->hash = $hash;
		$this->snapshots = array_fill_keys($data['snapshots'], true);
		$this->trash = array_fill_keys($data['trash'], true);
		// Catalogs written before user exports existed have no such key: default to empty rather
		// than adopting the location's current users/ listing (that would skip verification).
		$this->userExports = array_fill_keys($data['userExports'] ?? [], true);
		$this->anchor?->set($this->repositoryId, $this->gen, $this->hash);
	}

	/** Write the next generation after the in-memory state was changed. */
	public function write(): void {
		$next = $this->gen + 1;
		$plain = json_encode(['repository' => $this->repositoryId, 'gen' => $next, 'time' => gmdate('c'), 'prev' => $this->hash,
			'snapshots' => array_keys($this->snapshots), 'trash' => array_keys($this->trash),
			'userExports' => array_keys($this->userExports)], JSON_THROW_ON_ERROR);
		$encrypted = $this->cipher->encryptString($plain, $this->ad($next));
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $encrypted);
		rewind($stream);
		$this->backend->put($this->path($next), $stream);
		$this->gen = $next;
		$this->hash = hash('sha256', $encrypted);
		$this->anchor?->set($this->repositoryId, $this->gen, $this->hash);
		$old = $next - self::KEEP;
		if ($old > 0) {
			$this->backend->delete($this->path($old));
		}
	}

	/** @return array{0: array, 1: string} decoded catalog and the hash of its encrypted bytes */
	private function read(int $gen): array {
		try {
			$fh = $this->backend->get($this->path($gen));
		} catch (\Throwable) {
			throw new RepositoryException("Catalog generation $gen is missing (possible tampering)");
		}
		$encrypted = (string)stream_get_contents($fh);
		fclose($fh);
		$data = json_decode($this->cipher->decryptString($encrypted, $this->ad($gen)), true, 512, JSON_THROW_ON_ERROR);
		if (($data['repository'] ?? '') !== $this->repositoryId || ($data['gen'] ?? 0) !== $gen) {
			throw new RepositoryException("Catalog generation $gen belongs to another repository or position");
		}
		return [$data, hash('sha256', $encrypted)];
	}

	/** @return list<int> */
	private function generations(): array {
		return array_map(fn ($p) => (int)basename($p), $this->backend->list('catalog'));
	}

	private function path(int $gen): string {
		return sprintf('catalog/%010d', $gen);
	}

	private function ad(int $gen): string {
		return 'catalog:' . $this->repositoryId . ':' . $gen;
	}
}
