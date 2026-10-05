<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Notification;

use OCA\NgBackup\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10n,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->get(Application::APP_ID)->t('NG Backup');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10n->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		switch ($notification->getSubject()) {
			case 'run_failed':
				$notification->setParsedSubject($p['kind'] === 'restore'
					? $l->t('Restore from %s failed', [$p['target']])
					: $l->t('Backup to %s failed', [$p['target']]));
				$notification->setParsedMessage((string)($p['error'] ?? ''));
				break;
			case 'quota_warning':
				$notification->setParsedSubject($l->t('Backup location %1$s is at %2$s%% of its limit (%3$s of %4$s)', [$p['target'], $p['percent'], $p['used'], $p['limit']]));
				$notification->setParsedMessage($l->t('Backups stop when the limit is reached. Raise the limit, or let the retention policy remove older backups.'));
				break;
			case 'backup_stale':
				$notification->setParsedSubject($l->t('No successful backup to %1$s since %2$s', [$p['target'], $p['since']]));
				$notification->setParsedMessage($l->t('Check the backup settings and the server log.'));
				break;
			case 'security_event':
				$who = (string)($p['actor'] ?? '');
				$notification->setParsedSubject(match ($p['event'] ?? '') {
					'kit_downloaded' => $l->t('%s downloaded the backup recovery kit', [$who]),
					'target_removed' => $l->t('%1$s removed backup location %2$s', [$who, $p['target'] ?? '']),
					'append_only_off' => $l->t('%1$s turned off append-only for backup location %2$s', [$who, $p['target'] ?? '']),
					'retention_changed' => $l->t('%1$s changed the backup retention to %2$s', [$who, $p['policy'] ?? '']),
					'snapshots_forgotten' => $l->t('%1$s moved %2$s snapshots of %3$s to the trash; they are deleted permanently after %4$s unless restored', [$who, $p['count'] ?? '', $p['target'] ?? '', $p['until'] ?? '']),
					'snapshot_untrashed' => $l->t('%1$s restored snapshot %2$s of %3$s from the trash', [$who, $p['snapshot'] ?? '', $p['target'] ?? '']),
					'catalog_trusted' => $l->t('%1$s accepted an older state of backup location %2$s (catalog generation %3$s)', [$who, $p['target'] ?? '', $p['generation'] ?? '']),
					'slot_added' => $l->t('%1$s added backup passphrase slot %2$s (%3$s); download and confirm the new recovery kit', [$who, $p['slot'] ?? '', $p['label'] ?? '']),
					'slot_replaced' => $l->t('%1$s set a new passphrase for backup key slot %2$s (%3$s); download and confirm the new recovery kit', [$who, $p['slot'] ?? '', $p['label'] ?? '']),
					'slot_removed' => $l->t('%1$s removed backup passphrase slot %2$s; download and confirm the new recovery kit', [$who, $p['slot'] ?? '']),
					default => $l->t('NG Backup security event'),
				});
				$notification->setParsedMessage($l->t('If this was not you or another administrator, check who has admin access.'));
				break;
			default:
				throw new UnknownNotificationException();
		}
		$notification->setLink($this->url->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'ng_backup']));
		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
