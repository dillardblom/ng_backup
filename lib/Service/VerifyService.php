<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\Db\Target;
use OCP\Lock\LockedException;

/** Checksum audit of a snapshot (occ backup:verify), see Repository::verify(). */
final class VerifyService {
	public function __construct(
		private TargetService $targets,
		private LeaseService $leases,
	) {
	}

	/** @return array{filesChecked:int, blobsChecked:int, bytesChecked:int, missing:list<string>, failed:list<string>} */
	public function verify(Target $target, string $snapshotId, bool $deep = false): array {
		return $this->run($target, $deep, fn (\OCA\NgBackup\Repository\Repository $repo, callable $heartbeat): array => $repo->verify($snapshotId, $deep, $heartbeat));
	}

	/** @return array{filesChecked:int, blobsChecked:int, bytesChecked:int, missing:list<string>, failed:list<string>} */
	public function verifyUserExport(Target $target, string $manifestPath, bool $deep = false): array {
		return $this->run($target, $deep, fn (\OCA\NgBackup\Repository\Repository $repo, callable $heartbeat): array => $repo->verifyUserExport($manifestPath, $deep, $heartbeat));
	}

	private function run(Target $target, bool $deep, callable $fn): array {
		$ttl = $deep ? 600 : 300;
		try {
			return $this->leases->with(PruneService::lockKey($target), LeaseService::SHARED, $ttl, function (callable $refresh) use ($target, $deep, $ttl, $fn): array {
				$last = time();
				$heartbeat = function () use ($refresh, $ttl, &$last): void {
					if (time() - $last >= intdiv($ttl, 2)) {
						$refresh();
						$last = time();
					}
				};
				return $fn($this->targets->repository($target), $heartbeat);
			});
		} catch (LockedException) {
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is in progress; try again later');
		}
	}
}
