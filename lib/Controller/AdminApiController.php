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
final class AdminApiController extends Controller {
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
		private \OCA\NgBackup\Service\Schedule $schedule,
		private \OCA\NgBackup\Service\HeavyJobPause $heavyJobs,
		private \OCA\NgBackup\Service\CronHealth $cron,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @param bool $allRuns all runs instead of those of the last days (Status::RECENT_DAYS) */
	public function status(bool $allRuns = false): JSONResponse {
		$targets = [];
		foreach ($this->targets->list() as $t) {
			$targets[] = ['id' => $t->getId(), 'name' => $t->getName(), 'backend' => $t->getBackend(), 'path' => $t->getBasePath(),
				'repositoryId' => $t->getRepositoryId(), 'createdAt' => $t->getCreatedAt(), 'appendOnly' => (bool)$t->getAppendOnly(),
				'maxBytes' => $t->getMaxBytes(), 'storedBytes' => $this->alerts->lastStoredBytes($t),
				'schedule' => (string)$t->getSchedule(), 'scheduleZone' => $this->schedule->zoneOr($t->getScheduleZone())];
		}
		$shown = $this->runs->findShown($allRuns ? null : time() - \OCA\NgBackup\Command\Status::RECENT_DAYS * 86400);
		$queued = array_flip(array_map(static fn (Run $r): int => $r->getId(), array_slice($this->backups->runsInTurn(), 1)));
		$runs = array_map(fn (Run $r) => $this->runJson($r) + ['queued' => isset($queued[$r->getId()])], $shown);
		return new JSONResponse([
			'key' => ['initialized' => $this->keys->isInitialized(), 'fingerprint' => $this->keys->fingerprint(),
				'confirmation' => $this->keys->recoveryKitConfirmation(), 'everConfirmed' => $this->keys->isInitialized() && $this->keys->everConfirmed(),
				'slots' => $this->keys->isInitialized() ? $this->keys->slots() : [], 'maxSlots' => \OCA\NgBackup\Service\KeyService::MAX_SLOTS,
				'deleteDelayDays' => $this->keys->isInitialized() ? $this->keys->deleteDelayDays() : \OCA\NgBackup\Service\KeyService::DEFAULT_DELETE_DELAY],
			'statement' => KeyConfirm::STATEMENT,
			'confirmationPhrase' => KeyConfirm::CONFIRMATION,
			'targets' => $targets,
			'runs' => $runs,
			'olderRuns' => $allRuns ? 0 : $this->runs->countAll() - count($shown),
			'recentDays' => \OCA\NgBackup\Command\Status::RECENT_DAYS,
			'cron' => $this->cron->check(),
			'heavyJobs' => $this->heavyJobs->status(),
		]);
	}

	public function setPauseHeavyJobs(bool $enabled): JSONResponse {
		$this->heavyJobs->setEnabled($enabled);
		return new JSONResponse(['enabled' => $enabled]);
	}

	/** Seconds of backup work one tick() does: well within a normal web request. */
	public const TICK_SECONDS = 10.0;
	/** Seconds one request for the Bin reads, before the page shows how far it is and asks for more. */
	private const TRASH_PAGE_SECONDS = 5.0;

	/**
	 * Move running backups forward from the open admin page, when background jobs do not run on a
	 * working system cron (#13). The page calls this while a backup runs, so "Back up now" works
	 * without cron as long as the page stays open; scheduled backups still need cron. With a
	 * working system cron this does nothing: the background job does the work.
	 */
	public function tick(): JSONResponse {
		if ($this->cron->check()['ok']) {
			return new JSONResponse(['stepped' => 0]);
		}
		$deadline = microtime(true) + self::TICK_SECONDS;
		$stepped = 0;
		$this->heavyJobs->sync($this->backups->runsInTurn() !== []);
		foreach ($this->backups->runsInTurn() as $run) {
			if (microtime(true) >= $deadline) {
				break;
			}
			// step() returns at once when the background job (or another tab) holds the run.
			$next = $this->backups->step($run, $deadline);
			$stepped++;
			if ($next->getStatus() === Run::RUNNING) {
				break; // not finished: the next one waits for its turn
			}
		}
		return new JSONResponse(['stepped' => $stepped]);
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

	public function trash(int $targetId, string $after = ''): JSONResponse {
		try {
			$delay = $this->keys->deleteDelayDays() * 86400;
			$repo = $this->targets->repository($this->targets->get((string)$targetId));
			// In parts, so that the page can show how far it is (a read per removed restore point).
			$page = $repo->trashPage($after, microtime(true) + self::TRASH_PAGE_SECONDS);
			$out = [];
			foreach ($page['items'] as $id => $info) {
				$out[] = ['id' => $id, 'label' => $info['label'], 'time' => $info['time'], 'removedAt' => $info['forgottenAt'],
					'by' => $info['by'], 'deleteAfter' => $info['forgottenAt'] + $delay];
			}
			return new JSONResponse(['total' => $page['total'], 'items' => $out]);
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

	#[PasswordConfirmationRequired]
	public function addTarget(string $name, string $backend, string $auth, array $options = [], string $path = 'ng_backup', ?float $maxGb = null): JSONResponse {
		try {
			$r = $this->targets->add($name, $backend, $auth, $options, $path);
			if ($maxGb !== null && $maxGb > 0) {
				$this->targets->setMaxBytes($r['target'], (int)round($maxGb * 1073741824));
			}
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['id' => $r['target']->getId(), 'created' => $r['created']]);
	}

	/** The stored settings for the edit form; password values are left out (null). */
	#[PasswordConfirmationRequired]
	public function targetSettings(int $id): JSONResponse {
		$t = $this->targets->get((string)$id);
		return new JSONResponse(['name' => $t->getName(), 'backend' => $t->getBackend(), 'auth' => $t->getAuth(),
			'options' => $this->targets->editableOptions($t), 'path' => $t->getBasePath()]);
	}

	/** Options with a null value keep their stored value. */
	#[PasswordConfirmationRequired]
	public function updateTarget(int $id, string $name, string $backend, string $auth, array $options = [], string $path = 'ng_backup'): JSONResponse {
		try {
			$t = $this->targets->get((string)$id);
			$before = $t->getName();
			$t = $this->targets->update($t, $name, $backend, $auth, $options, $path);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->alerts->securityEvent('target_changed', ['target' => $before]);
		return new JSONResponse(['id' => $t->getId()]);
	}

	/** Size limit in GiB; null or 0 = unlimited. */
	#[PasswordConfirmationRequired]
	public function setTargetLimit(int $id, ?float $maxGb = null): JSONResponse {
		$t = $this->targets->setMaxBytes($this->targets->get((string)$id), ($maxGb !== null && $maxGb > 0) ? (int)round($maxGb * 1073741824) : null);
		return new JSONResponse(['maxBytes' => $t->getMaxBytes()]);
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

	/** @param string $timezone the administrator's zone (the browser's); empty: the default one */
	public function setTargetSchedule(int $id, string $schedule, string $timezone = ''): JSONResponse {
		try {
			$t = $this->targets->setSchedule($this->targets->get((string)$id), $schedule, $timezone !== '' ? $timezone : $this->schedule->get()['timezone']);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['schedule' => $t->getSchedule(), 'scheduleZone' => $t->getScheduleZone()]);
	}

	public function snapshots(int $targetId): JSONResponse {
		$out = [];
		foreach ($this->snapshots->findForTarget($targetId) as $s) {
			$out[] = ['id' => $s->getSnapshotId(), 'kind' => $s->getKind(), 'label' => $s->getLabel(), 'createdAt' => $s->getCreatedAt(),
				'files' => $s->getFiles(), 'bytes' => $s->getBytes(), 'note' => $s->getNote(), 'pinned' => (bool)$s->getPinned()];
		}
		return new JSONResponse($out);
	}

	public function setSnapshotNote(int $targetId, string $snapshotId, string $note = ''): JSONResponse {
		try {
			$this->prune->setNote($this->targets->get((string)$targetId), $snapshotId, $note);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['ok' => true]);
	}

	public function setSnapshotPinned(int $targetId, string $snapshotId, bool $pinned = true): JSONResponse {
		try {
			$this->prune->setPinned($this->targets->get((string)$targetId), $snapshotId, $pinned);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['ok' => true]);
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
		try {
			$target = $this->targets->get((string)$targetId);
		} catch (\Throwable) {
			return new JSONResponse(['error' => 'No such location'], Http::STATUS_NOT_FOUND);
		}
		$run = new Run();
		$run->setTargetId($target->getId());
		$run->setKind('restore');
		$run->setStatus(Run::RUNNING);
		$run->setPhase('queued');
		$run->setState(json_encode(['snapshot' => $snapshotId, 'path' => $path, 'mode' => $mode,
			'by' => $this->session->getUser()?->getUID()], JSON_THROW_ON_ERROR));
		$run->setSnapshotId($snapshotId);
		$run->setStartedAt(time());
		$run->setUpdatedAt(time());
		try {
			$run = $this->prune->underStartLock($target, fn () => $this->runs->insert($run));
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
		$this->jobs->add(RestoreJob::class, ['run' => $run->getId()]);
		return new JSONResponse($this->runJson($run));
	}

	private function runJson(Run $r): array {
		$snapshot = null;
		if ($r->getSnapshotId() !== null) {
			try {
				$s = $this->snapshots->findOne($r->getTargetId(), $r->getSnapshotId());
				$snapshot = ['createdAt' => $s->getCreatedAt(), 'label' => $s->getLabel(), 'note' => $s->getNote(), 'pinned' => (bool)$s->getPinned()];
			} catch (\Throwable) {
				// Snapshot already pruned or trashed: the run keeps its id, just not the date.
			}
		}
		return ['id' => $r->getId(), 'targetId' => $r->getTargetId(), 'kind' => $r->getKind(), 'status' => $r->getStatus(),
			'phase' => $r->getPhase(), 'snapshotId' => $r->getSnapshotId(), 'snapshot' => $snapshot, 'startedAt' => $r->getStartedAt(),
			'updatedAt' => $r->getUpdatedAt(), 'finishedAt' => $r->getFinishedAt(), 'error' => $r->getError(),
			'stats' => json_decode($r->getStats() ?? 'null', true), 'progress' => \OCA\NgBackup\Job\BackupRun::progressOf($r),
			'waitingFor' => $r->getKind() === BackupService::KIND_FULL ? \OCA\NgBackup\Service\CronHealth::waitingFor($r) : null];
	}
}
