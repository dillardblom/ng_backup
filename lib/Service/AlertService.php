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
	public function securityEvent(string $event, array $params = [], bool $notify = true): void {
		$actor = $this->session->getUser()?->getUID() ?? (PHP_SAPI === 'cli' ? 'occ' : 'unknown');
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
