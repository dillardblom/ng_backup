<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\Db\Target;
use OCP\IAppConfig;
use OCP\Lock\LockedException;

/**
 * Checksum audit of a snapshot (occ backup:verify), see Repository::verify(). On a large
 * repository a check takes hours (a deep one days), so how far it is gets stored every
 * PROGRESS_SECONDS, together with how it ended: `occ backup:status` shows it.
 */
final class VerifyService {
	public const PROGRESS_SECONDS = 30;
	private const KEY_PREFIX = 'verify_progress_';

	public function __construct(
		private TargetService $targets,
		private LeaseService $leases,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param ?callable(array):void $onProgress called with the stored progress every PROGRESS_SECONDS
	 * @return array{filesChecked:int, blobsChecked:int, bytesChecked:int, missing:list<string>, failed:list<string>, unreadable:list<string>, readError:string}
	 */
	public function verify(Target $target, string $snapshotId, bool $deep = false, ?callable $onProgress = null): array {
		return $this->run($target, $snapshotId, $deep, $onProgress, fn (\OCA\NgBackup\Repository\Repository $repo, callable $heartbeat): array => $repo->verify($snapshotId, $deep, $heartbeat));
	}

	/**
	 * @param ?callable(array):void $onProgress called with the stored progress every PROGRESS_SECONDS
	 * @return array{filesChecked:int, blobsChecked:int, bytesChecked:int, missing:list<string>, failed:list<string>, unreadable:list<string>, readError:string}
	 */
	public function verifyUserExport(Target $target, string $manifestPath, bool $deep = false, ?callable $onProgress = null): array {
		return $this->run($target, $manifestPath, $deep, $onProgress, fn (\OCA\NgBackup\Repository\Repository $repo, callable $heartbeat): array => $repo->verifyUserExport($manifestPath, $deep, $heartbeat));
	}

	/** One line on a check's progress or outcome, for occ (see progress()). */
	public static function describe(array $s): string {
		$size = static fn (int $b): string => $b >= 1073741824 ? sprintf('%.1f GiB', (float)$b / 1073741824.0) : sprintf('%.1f MiB', (float)$b / 1048576.0);
		$count = static fn (int $done, mixed $total): string => number_format($done)
			. (is_int($total) && $total > 0 ? '/' . number_format($total) . sprintf(' (%d%%)', intdiv(100 * $done, $total)) : '');
		$deep = ($s['deep'] ?? false) === true;
		$kind = $deep ? 'deep check' : 'check';
		if (($s['status'] ?? '') === 'running') {
			$phase = match ($s['phase'] ?? '') {
				'index' => 'index files ' . $count((int)$s['done'], $s['total'] ?? null),
				'packs' => 'pack folders ' . $count((int)$s['done'], $s['total'] ?? null),
				'files', 'database' => 'files ' . $count((int)$s['done'], $s['total'] ?? null) . ', ' . number_format((int)($s['blobs'] ?? 0)) . ' blobs'
					. ($deep ? ', ' . $size((int)($s['bytes'] ?? 0)) . ' decrypted' : ''),
				default => 'starting',
			};
			$age = time() - (int)($s['updated'] ?? 0);
			return sprintf('%s running since %s: %s; updated %s ago%s', $kind, date('Y-m-d H:i', (int)$s['started']), $phase,
				$age < 120 ? $age . ' s' : intdiv($age, 60) . ' min',
				$age > 600 ? ' (no progress for a while: the process may have stopped)' : '');
		}
		$took = gmdate('H:i:s', max(0, (int)($s['finished'] ?? 0) - (int)($s['started'] ?? 0)));
		return match ($s['status'] ?? '') {
			'ok' => sprintf('%s OK on %s (took %s): %s files, %s blobs%s', $kind, date('Y-m-d H:i', (int)$s['finished']), $took,
				number_format((int)($s['files'] ?? 0)), number_format((int)($s['blobs'] ?? 0)), $deep ? ', ' . $size((int)($s['bytes'] ?? 0)) . ' decrypted' : ''),
			'problems' => sprintf('%s found PROBLEMS on %s: %d missing, %d failed, %d unreadable blob(s); run occ backup:verify again for the details',
				$kind, date('Y-m-d H:i', (int)$s['finished']), (int)($s['missing'] ?? 0), (int)($s['failedBlobs'] ?? 0), (int)($s['unreadable'] ?? 0)),
			default => sprintf('%s FAILED on %s: %s', $kind, date('Y-m-d H:i', (int)($s['finished'] ?? 0)), (string)($s['error'] ?? 'unknown error')),
		};
	}

	/**
	 * The stored state of the last check per location: running (with how far it is) or how it
	 * ended.
	 *
	 * @return array<int, array<string, mixed>> by location id
	 */
	public function progress(): array {
		$result = [];
		foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
			if (!str_starts_with($key, self::KEY_PREFIX)) {
				continue;
			}
			$state = json_decode($this->appConfig->getValueString(Application::APP_ID, $key, '', lazy: true), true);
			if (is_array($state)) {
				$result[(int)substr($key, strlen(self::KEY_PREFIX))] = $state;
			}
		}
		ksort($result);
		return $result;
	}

	private function run(Target $target, string $what, bool $deep, ?callable $onProgress, callable $fn): array {
		$ttl = $deep ? 600 : 300;
		$state = ['what' => $what, 'deep' => $deep, 'status' => 'running', 'started' => time(), 'updated' => time(),
			'pid' => getmypid(), 'phase' => 'starting', 'done' => 0, 'total' => null, 'blobs' => 0, 'bytes' => 0];
		try {
			$this->store($target, $state);
		} catch (\Throwable) {
			// progress reports are a convenience; the check itself goes ahead
		}
		try {
			$result = $this->leases->with(PruneService::lockKey($target), LeaseService::SHARED, $ttl, function (callable $refresh) use ($target, $ttl, $fn, $onProgress, &$state): array {
				$lastRefresh = time();
				$lastStore = time();
				$heartbeat = function (?array $progress = null) use ($refresh, $ttl, $target, $onProgress, &$lastRefresh, &$lastStore, &$state): void {
					$now = time();
					if ($now - $lastRefresh >= intdiv($ttl, 2)) {
						$refresh();
						$lastRefresh = $now;
					}
					if ($progress !== null && $now - $lastStore >= self::PROGRESS_SECONDS) {
						$state = array_merge($state, $progress, ['updated' => $now]);
						try {
							$this->store($target, $state);
						} catch (\Throwable) {
							// a progress report that cannot be stored must not stop the check
						}
						$lastStore = $now;
						if ($onProgress !== null) {
							$onProgress($state);
						}
					}
				};
				return $fn($this->targets->repository($target), $heartbeat);
			});
		} catch (LockedException) {
			$this->finish($target, $state, 'failed', ['error' => 'A cleanup of the location was in progress']);
			throw new \RuntimeException('A cleanup of ' . $target->getName() . ' is in progress; try again later');
		} catch (\Throwable $e) {
			$this->finish($target, $state, 'failed', ['error' => $e->getMessage()]);
			throw $e;
		}
		$problems = count($result['missing']) + count($result['failed']) + count($result['unreadable']);
		$this->finish($target, $state, $problems === 0 ? 'ok' : 'problems', [
			'phase' => 'done', 'files' => $result['filesChecked'], 'blobs' => $result['blobsChecked'], 'bytes' => $result['bytesChecked'],
			'missing' => count($result['missing']), 'failedBlobs' => count($result['failed']), 'unreadable' => count($result['unreadable']),
		]);
		return $result;
	}

	private function finish(Target $target, array $state, string $status, array $extra): void {
		try {
			$this->store($target, array_merge($state, $extra, ['status' => $status, 'updated' => time(), 'finished' => time()]));
		} catch (\Throwable) {
			// the check's own result counts; its stored summary is a convenience
		}
	}

	private function store(Target $target, array $state): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_PREFIX . $target->getId(), json_encode($state, JSON_THROW_ON_ERROR), lazy: true);
	}
}
