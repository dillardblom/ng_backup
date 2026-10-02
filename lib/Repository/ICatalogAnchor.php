<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

/**
 * Where the server remembers the newest catalog generation it has seen for a repository, outside
 * the (untrusted) location. A location that presents an older generation, or a chain that does
 * not lead back to this anchor, is rolled back or tampered with.
 */
interface ICatalogAnchor {
	/** @return array{gen:int, hash:string}|null */
	public function get(string $repositoryId): ?array;

	public function set(string $repositoryId, int $gen, string $hash): void;
}
