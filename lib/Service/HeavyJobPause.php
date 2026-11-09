<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Keeps heavy background jobs of other apps from running while a backup reads the same disks, by
 * reserving them in Nextcloud's job list: no app is turned off, and a job that is running goes on.
 * Nextcloud itself gives up on a reservation after 12 hours, so a pause that is never ended, because
 * cron or this app is gone, ends by itself. It lasts at most MAX_SECONDS, then the jobs get REST_SECONDS.
 */
final class HeavyJobPause {
	public const ENABLED_KEY = 'pause_heavy_jobs';
	private const SINCE_KEY = 'heavy_jobs_paused_since';
	private const REST_KEY = 'heavy_jobs_rest_until';
	public const MAX_SECONDS = 6 * 3600;
	public const REST_SECONDS = 3600;
	/** Nextcloud runs a job again this long after it was reserved. */
	private const RESERVATION_SECONDS = 12 * 3600;

	/** Class, or the start of a class name ending in a backslash => what to call it */
	private const JOBS = [
		'OCA\\Memories\\' => 'Memories',
		'OCA\\Files_Antivirus\\BackgroundJob\\BackgroundScanner' => 'Antivirus background scan',
		'OCA\\Recognize\\' => 'Recognize',
		'OCA\\PreviewGenerator\\' => 'Preview Generator',
		'OCA\\FullTextSearch\\' => 'Full text search',
	];

	public const NOTHING = 'nothing';
	public const PAUSE = 'pause';
	public const RELEASE = 'release';
	public const REST = 'rest';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/** @return self::NOTHING|self::PAUSE|self::RELEASE|self::REST */
	public static function decide(bool $enabled, bool $backupRunning, int $since, int $restUntil, int $now): string {
		if ($since > 0 && $now - $since >= self::MAX_SECONDS) {
			return self::REST;
		}
		if ($enabled && $backupRunning && $now >= $restUntil) {
			return self::PAUSE;
		}
		return $since > 0 ? self::RELEASE : self::NOTHING;
	}

	/** Call regularly: pauses or ends the pause as needed. A problem here never stops a backup. */
	public function sync(bool $backupRunning): void {
		try {
			$this->apply($backupRunning, time());
		} catch (\Throwable $e) {
			$this->logger->warning('NG Backup: pausing heavy background jobs failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	private function apply(bool $backupRunning, int $now): void {
		$since = $this->appConfig->getValueInt(Application::APP_ID, self::SINCE_KEY, 0);
		$restUntil = $this->appConfig->getValueInt(Application::APP_ID, self::REST_KEY, 0);
		switch (self::decide($this->enabled(), $backupRunning, $since, $restUntil, $now)) {
			case self::PAUSE:
				if ($since === 0) {
					$since = $now;
					$this->appConfig->setValueInt(Application::APP_ID, self::SINCE_KEY, $since);
					$this->logger->info('NG Backup: heavy background jobs paused while a backup runs');
				}
				$this->reserve($since, $now); // also the jobs that were added or running until now
				break;
			case self::REST:
				$this->appConfig->setValueInt(Application::APP_ID, self::REST_KEY, $now + self::REST_SECONDS);
				$this->release($since);
				$this->logger->info('NG Backup: heavy background jobs get their turn for an hour during a long backup');
				break;
			case self::RELEASE:
				$this->release($since);
				$this->logger->info('NG Backup: heavy background jobs run again');
				break;
		}
	}

	private function reserve(int $stamp, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('jobs')
			->set('reserved_at', $qb->createNamedParameter($stamp, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->lte('reserved_at', $qb->createNamedParameter($now - self::RESERVATION_SECONDS, IQueryBuilder::PARAM_INT)))
			->andWhere($this->isHeavy($qb));
		$qb->executeStatement();
	}

	/** Only our own reservations: a job that Nextcloud reserved at another moment is running. */
	private function release(int $stamp): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('jobs')
			->set('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('reserved_at', $qb->createNamedParameter($stamp, IQueryBuilder::PARAM_INT)))
			->andWhere($this->isHeavy($qb));
		$qb->executeStatement();
		$this->appConfig->deleteKey(Application::APP_ID, self::SINCE_KEY);
	}

	/** @return \OCP\DB\QueryBuilder\ICompositeExpression */
	private function isHeavy(IQueryBuilder $qb) {
		$conditions = [];
		foreach (array_keys(self::JOBS) as $class) {
			$conditions[] = str_ends_with($class, '\\')
				? $qb->expr()->like('class', $qb->createNamedParameter($this->db->escapeLikeParameter($class) . '%'))
				: $qb->expr()->eq('class', $qb->createNamedParameter($class));
		}
		return $qb->expr()->orX(...$conditions);
	}

	public function enabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::ENABLED_KEY, '1') !== '0';
	}

	public function setEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, self::ENABLED_KEY, $enabled ? '1' : '0');
	}

	/** @return array{enabled: bool, since: int, jobs: list<string>} what is paused now */
	public function status(): array {
		$since = $this->appConfig->getValueInt(Application::APP_ID, self::SINCE_KEY, 0);
		$jobs = [];
		if ($since > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('class')->from('jobs')
				->where($qb->expr()->eq('reserved_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
				->andWhere($this->isHeavy($qb));
			$result = $qb->executeQuery();
			while (($class = $result->fetchOne()) !== false) {
				foreach (self::JOBS as $start => $name) {
					if (str_starts_with((string)$class, $start)) {
						$jobs[$name] = true;
					}
				}
			}
			$result->closeCursor();
		}
		return ['enabled' => $this->enabled(), 'since' => $since, 'jobs' => array_map('strval', array_keys($jobs))];
	}
}
