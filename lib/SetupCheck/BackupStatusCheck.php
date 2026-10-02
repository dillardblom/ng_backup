<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\SetupCheck;

use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Service\AlertService;
use OCA\NgBackup\Service\BackupService;
use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\TargetService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** Shows the backup state in the admin overview (Security & setup warnings). */
class BackupStatusCheck implements ISetupCheck {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
		private KeyService $keys,
		private TargetService $targets,
		private RunMapper $runs,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l->t('NG Backup');
	}

	public function run(): SetupResult {
		$link = $this->url->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'ng_backup']);
		if (!$this->keys->isInitialized() || !$this->keys->everConfirmed()) {
			return SetupResult::error($this->l->t('Backups are not set up: create the backup key and confirm the recovery kit.'), $link);
		}
		if ($this->keys->recoveryKitConfirmation() === null) {
			return SetupResult::error($this->l->t('The backup passphrase was changed: download and confirm the new recovery kit.'), $link);
		}
		$targets = $this->targets->list();
		if ($targets === []) {
			return SetupResult::warning($this->l->t('No backup location is configured.'), $link);
		}
		$problems = [];
		foreach ($targets as $t) {
			$last = $this->runs->lastFinished($t->getId(), BackupService::KIND_FULL);
			if ($last === null) {
				$problems[] = $this->l->t('%s: no successful backup yet', [$t->getName()]);
			} elseif (time() - (int)$last->getFinishedAt() > AlertService::STALE_AFTER) {
				$problems[] = $this->l->t('%1$s: last successful backup %2$s', [$t->getName(), date('Y-m-d H:i', (int)$last->getFinishedAt())]);
			}
		}
		foreach ($this->runs->findRecent(10) as $r) {
			if ($r->getStatus() === Run::FAILED && $r->getStartedAt() > time() - AlertService::STALE_AFTER) {
				$problems[] = $this->l->t('A %1$s run failed: %2$s', [$r->getKind(), mb_strimwidth((string)$r->getError(), 0, 120, '…')]);
				break;
			}
		}
		return $problems === []
			? SetupResult::success($this->l->t('Recent backups to all locations succeeded.'))
			: SetupResult::warning(implode("\n", $problems), $link);
	}
}
