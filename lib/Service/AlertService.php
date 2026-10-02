<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Db\Target;
use OCP\IGroupManager;
use OCP\Notification\IManager;

/** Notifies administrators about failed runs and locations without a recent successful backup. */
class AlertService {
	public const STALE_AFTER = 2 * 86400;

	public function __construct(
		private IManager $notifications,
		private IGroupManager $groups,
		private RunMapper $runs,
		private \OCP\IAppConfig $appConfig,
		private \OCP\EventDispatcher\IEventDispatcher $events,
		private \OCP\IUserSession $session,
	) {
	}

	/**
	 * Security-relevant change (recovery kit downloaded, location removed, append-only off,
	 * retention changed, snapshots forgotten): written to the audit log (admin_audit) and sent
	 * to every administrator, so a single compromised admin account cannot do it unnoticed.
	 *
	 * @param array<string, string> $params
	 */
	public function actor(): string {
		return $this->session->getUser()?->getUID() ?? (PHP_SAPI === 'cli' ? 'occ' : 'unknown');
	}

	public function securityEvent(string $event, array $params = [], bool $notify = true): void {
		$actor = $this->actor();
		$params = ['actor' => $actor] + $params;
		$this->events->dispatchTyped(new \OCP\Log\Audit\CriticalActionPerformedEvent(
			'NG Backup: %s by %s ' . json_encode(array_diff_key($params, ['actor' => 1]), JSON_UNESCAPED_SLASHES), ['event' => $event, 'actor' => $actor]));
		if ($notify) {
			$this->notifyAdmins('security_event', $event . '-' . time(), ['event' => $event] + $params);
		}
	}

	public function runFailed(Run $run, string $targetName): void {
		$this->notifyAdmins('run_failed', 'run-' . $run->getId(),
			['kind' => $run->getKind(), 'target' => $targetName, 'error' => mb_strimwidth((string)$run->getError(), 0, 300, '…')]);
	}

	/**
	 * Remember how much is stored on a location (for the settings page) and warn administrators
	 * once a day when it is at 80% of its limit or more.
	 */
	public function storedBytes(Target $target, int $bytes): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stored_' . $target->getId(), (string)$bytes);
		$limit = $target->getMaxBytes();
		if ($limit === null || $limit <= 0 || $bytes < 0.8 * $limit) {
			return;
		}
		$key = 'quota_notified_' . $target->getId();
		if ($this->appConfig->getValueString(Application::APP_ID, $key, '') === date('Y-m-d')) {
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, $key, date('Y-m-d'));
		$this->notifyAdmins('quota_warning', 'quota-' . $target->getId() . '-' . date('Y-m-d'), [
			'target' => $target->getName(), 'percent' => (string)round($bytes / $limit * 100),
			'used' => self::gib($bytes), 'limit' => self::gib($limit)]);
	}

	private static function gib(int $bytes): string {
		$g = $bytes / 1073741824;
		return sprintf($g < 10 ? '%.2f GiB' : '%.1f GiB', $g);
	}

	public function lastStoredBytes(Target $target): ?int {
		$v = $this->appConfig->getValueString(Application::APP_ID, 'stored_' . $target->getId(), '');
		return $v === '' ? null : (int)$v;
	}

	/** At most one notification per location per day. */
	public function checkStale(Target $target): void {
		$last = $this->runs->lastFinished($target->getId(), BackupService::KIND_FULL);
		$lastTime = $last?->getFinishedAt() ?? $target->getCreatedAt();
		if (time() - $lastTime < self::STALE_AFTER || $this->runs->findRunning($target->getId(), BackupService::KIND_FULL) !== []) {
			return;
		}
		$key = 'stale_notified_' . $target->getId();
		if ($this->appConfig->getValueString(Application::APP_ID, $key, '') === date('Y-m-d')) {
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, $key, date('Y-m-d'));
		$this->notifyAdmins('backup_stale', 'stale-' . $target->getId() . '-' . date('Y-m-d'),
			['target' => $target->getName(), 'since' => $last ? date('Y-m-d H:i', $lastTime) : 'never']);
	}

	private function notifyAdmins(string $subject, string $objectId, array $params): void {
		foreach ($this->groups->get('admin')?->getUsers() ?? [] as $admin) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($admin->getUID())
				->setDateTime(new \DateTime())
				->setObject($subject, $objectId)
				->setSubject($subject, $params);
			$this->notifications->notify($n);
		}
	}
}
