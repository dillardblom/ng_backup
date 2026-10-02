<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Controller;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\BackgroundJob\RestoreJob;
use OCA\NgBackup\Command\KeyConfirm;
use OCA\NgBackup\Db\Run;
use OCA\NgBackup\Db\RunMapper;
use OCA\NgBackup\Db\SnapshotMapper;
use OCA\NgBackup\Service\BackupService;
use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\RestoreService;
use OCA\NgBackup\Service\TargetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * JSON API for the admin settings page. Every method requires an admin (the AppFramework
 * default for controllers without NoAdminRequired) and a valid CSRF token.
 */
class AdminApiController extends Controller {
	public function __construct(
		IRequest $request,
		private KeyService $keys,
		private TargetService $targets,
		private BackupService $backups,
		private RestoreService $restore,
		private RunMapper $runs,
		private SnapshotMapper $snapshots,
		private IAppConfig $appConfig,
		private IJobList $jobs,
		private IUserSession $session,
		private \OCA\NgBackup\Service\AlertService $alerts,
		private \OCA\NgBackup\Service\KeySlotService $slots,
		private \OCA\NgBackup\Service\PruneService $prune,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function status(): JSONResponse {
		$targets = [];
		foreach ($this->targets->list() as $t) {
			$targets[] = ['id' => $t->getId(), 'name' => $t->getName(), 'backend' => $t->getBackend(), 'path' => $t->getBasePath(),
				'repositoryId' => $t->getRepositoryId(), 'createdAt' => $t->getCreatedAt()];
		}
		$runs = array_map(fn (Run $r) => $this->runJson($r), $this->runs->findRecent(15));
		return new JSONResponse([
			'key' => ['initialized' => $this->keys->isInitialized(), 'fingerprint' => $this->keys->fingerprint(),
				'confirmation' => $this->keys->recoveryKitConfirmation(), 'everConfirmed' => $this->keys->isInitialized() && $this->keys->everConfirmed(),
				'slots' => $this->keys->isInitialized() ? $this->keys->slots() : [], 'maxSlots' => \OCA\NgBackup\Service\KeyService::MAX_SLOTS,
				'deleteDelayDays' => $this->keys->isInitialized() ? $this->keys->deleteDelayDays() : \OCA\NgBackup\Service\KeyService::DEFAULT_DELETE_DELAY],
			'statement' => KeyConfirm::STATEMENT,
			'confirmationPhrase' => KeyConfirm::CONFIRMATION,
			'targets' => $targets,
			'runs' => $runs,
			'schedule' => $this->appConfig->getValueString(Application::APP_ID, 'schedule_time', ''),
		]);
	}

	#[PasswordConfirmationRequired]
	public function initKey(string $passphrase, int $deleteDelayDays = \OCA\NgBackup\Service\KeyService::DEFAULT_DELETE_DELAY, string $label = 'Slot 1'): JSONResponse {
		try {
			$this->keys->initialize($passphrase, $deleteDelayDays, $label);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['fingerprint' => $this->keys->fingerprint()]);
	}

	/**
	 * Plain download link (no CSRF token on a navigation), therefore guarded by a recent password
	 * confirmation instead, logged to the audit log and announced to all administrators. The kit
	 * only holds the passphrase-wrapped key: useless without the passphrase.
	 */
	#[NoCSRFRequired]
	#[PasswordConfirmationRequired]
	public function downloadKit(): DataDownloadResponse|JSONResponse {
		if (!$this->keys->isInitialized()) {
			return new JSONResponse(['error' => 'No backup key yet'], Http::STATUS_BAD_REQUEST);
		}
		$this->alerts->securityEvent('kit_downloaded');
		$json = json_encode($this->keys->recoveryKit(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		return new DataDownloadResponse($json, 'ng_backup-recovery-kit-' . $this->keys->fingerprint() . '.json', 'application/json');
	}

	/** The admin must tick the statement, send the exact phrase and the code from the kit file. */
	#[PasswordConfirmationRequired]
	public function confirmKit(bool $accepted, string $phrase, string $code = ''): JSONResponse {
		if (!$accepted || $phrase !== KeyConfirm::CONFIRMATION) {
			return new JSONResponse(['error' => 'Not confirmed'], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->keys->checkKitCode($code)) {
			return new JSONResponse(['error' => 'The confirmation code does not match the current recovery kit'], Http::STATUS_BAD_REQUEST);
		}
		$this->keys->confirmRecoveryKit($this->session->getUser()?->getUID() ?? 'unknown');
		return new JSONResponse(['confirmation' => $this->keys->recoveryKitConfirmation()]);
	}

	#[PasswordConfirmationRequired]
	public function addSlot(string $passphrase, string $label): JSONResponse {
		try {
			return new JSONResponse(['slot' => $this->slots->add($passphrase, $label)]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[PasswordConfirmationRequired]
	public function replaceSlot(int $slot, string $passphrase, ?string $label = null): JSONResponse {
		try {
			$this->slots->replace($slot, $passphrase, $label);
			return new JSONResponse([]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[PasswordConfirmationRequired]
	public function removeSlot(int $slot): JSONResponse {
		try {
			$this->slots->remove($slot);
			return new JSONResponse([]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	public function trash(int $targetId): JSONResponse {
		try {
			$delay = $this->keys->deleteDelayDays() * 86400;
			$out = [];
			foreach ($this->targets->repository($this->targets->get((string)$targetId))->trash() as $id => $info) {
				$out[] = ['id' => $id, 'label' => $info['label'], 'time' => $info['time'], 'removedAt' => $info['forgottenAt'],
					'by' => $info['by'], 'deleteAfter' => $info['forgottenAt'] + $delay];
			}
			return new JSONResponse($out);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[PasswordConfirmationRequired]
	public function untrash(int $targetId, string $snapshotId): JSONResponse {
		try {
			$this->prune->untrash($this->targets->get((string)$targetId), $snapshotId);
			return new JSONResponse([]);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	public function backends(): JSONResponse {
		return new JSONResponse($this->targets->availableBackends());
	}

	public function addTarget(string $name, string $backend, string $auth, array $options = [], string $path = 'ng_backup'): JSONResponse {
		try {
			$r = $this->targets->add($name, $backend, $auth, $options, $path);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['id' => $r['target']->getId(), 'created' => $r['created']]);
	}

	#[PasswordConfirmationRequired]
	public function removeTarget(int $id): JSONResponse {
		$target = $this->targets->get((string)$id);
		$this->targets->remove($target);
		$this->alerts->securityEvent('target_removed', ['target' => $target->getName()]);
		return new JSONResponse([]);
	}

	public function testTarget(int $id): JSONResponse {
		try {
			$repo = $this->targets->repository($this->targets->get((string)$id));
			return new JSONResponse(['ok' => true, 'snapshots' => count($repo->listSnapshots())]);
		} catch (\Throwable $e) {
			return new JSONResponse(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	public function startBackup(int $targetId, string $label = ''): JSONResponse {
		try {
			$run = $this->backups->start($this->targets->get((string)$targetId), $label);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->runJson($run));
	}

	public function setSchedule(string $time): JSONResponse {
		if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
			return new JSONResponse(['error' => 'Use HH:MM or empty'], Http::STATUS_BAD_REQUEST);
		}
		$this->appConfig->setValueString(Application::APP_ID, 'schedule_time', $time);
		return new JSONResponse(['schedule' => $time]);
	}

	public function snapshots(int $targetId): JSONResponse {
		$out = [];
		foreach ($this->snapshots->findForTarget($targetId) as $s) {
			$out[] = ['id' => $s->getSnapshotId(), 'kind' => $s->getKind(), 'label' => $s->getLabel(), 'createdAt' => $s->getCreatedAt(),
				'files' => $s->getFiles(), 'bytes' => $s->getBytes()];
		}
		return new JSONResponse($out);
	}

	public function browse(int $targetId, string $snapshotId, string $path = ''): JSONResponse {
		try {
			return new JSONResponse($this->restore->browse($this->targets->get((string)$targetId), $snapshotId, $path));
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Queue a restore of user files; it runs as a background job and shows up in the runs. */
	#[PasswordConfirmationRequired]
	public function startRestore(int $targetId, string $snapshotId, string $path, string $mode = RestoreService::MODE_NEW_FOLDER): JSONResponse {
		if (!in_array($mode, [RestoreService::MODE_NEW_FOLDER, RestoreService::MODE_MERGE, RestoreService::MODE_REPLACE], true)
			|| !\OCA\NgBackup\Repository\Repository::isSafePath($path) || !preg_match('#^data/[^/]+/files(/.*)?$#', $path)) {
			return new JSONResponse(['error' => 'Invalid path or mode'], Http::STATUS_BAD_REQUEST);
		}
		$run = new Run();
		$run->setTargetId($targetId);
		$run->setKind('restore');
		$run->setStatus(Run::RUNNING);
		$run->setPhase('queued');
		$run->setState(json_encode(['snapshot' => $snapshotId, 'path' => $path, 'mode' => $mode,
			'by' => $this->session->getUser()?->getUID()], JSON_THROW_ON_ERROR));
		$run->setSnapshotId($snapshotId);
		$run->setStartedAt(time());
		$run->setUpdatedAt(time());
		$run = $this->runs->insert($run);
		$this->jobs->add(RestoreJob::class, ['run' => $run->getId()]);
		return new JSONResponse($this->runJson($run));
	}

	private function runJson(Run $r): array {
		return ['id' => $r->getId(), 'targetId' => $r->getTargetId(), 'kind' => $r->getKind(), 'status' => $r->getStatus(),
			'phase' => $r->getPhase(), 'snapshotId' => $r->getSnapshotId(), 'startedAt' => $r->getStartedAt(),
			'updatedAt' => $r->getUpdatedAt(), 'finishedAt' => $r->getFinishedAt(), 'error' => $r->getError(),
			'stats' => json_decode($r->getStats() ?? 'null', true)];
	}
}
