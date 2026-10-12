<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

/** Optional local cache of pack index entries (the repository's index/ files stay authoritative). */
interface IIndexCache {
	/** @return array<string, list<array{id:string, offset:int, length:int, raw:int, flags:int}>> packId => entries */
	public function all(string $repositoryId): array;

	/** @param list<array{id:string, offset:int, length:int, raw:int, flags:int}> $entries */
	public function put(string $repositoryId, string $packId, array $entries): void;

	/**
	 * put() for many packs at once (one database transaction).
	 *
	 * @param array<string, list<array{id:string, offset:int, length:int, raw:int, flags:int}>> $packs packId => entries
	 */
	public function putMany(string $repositoryId, array $packs): void;

	/**
	 * Replace everything cached for the repository with $packs (one database transaction).
	 *
	 * @param array<string, list<array{id:string, offset:int, length:int, raw:int, flags:int}>> $packs packId => entries
	 */
	public function replaceAll(string $repositoryId, array $packs): void;

	/** @param list<string> $packIds */
	public function remove(string $repositoryId, array $packIds): void;
}
