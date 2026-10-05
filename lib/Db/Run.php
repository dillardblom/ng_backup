<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getTargetId()
 * @method void setTargetId(int $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method string getPhase()
 * @method void setPhase(string $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method string|null getStats()
 * @method void setStats(?string $v)
 * @method string|null getError()
 * @method void setError(?string $v)
 * @method string|null getSnapshotId()
 * @method void setSnapshotId(?string $v)
 * @method int getStartedAt()
 * @method void setStartedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method int|null getFinishedAt()
 * @method void setFinishedAt(?int $v)
 */
final class Run extends Entity {
	public const RUNNING = 'running';
	public const DONE = 'done';
	public const FAILED = 'failed';
	public const CANCELLED = 'cancelled';

	protected int $targetId = 0;
	protected string $kind = '';
	protected string $status = '';
	protected string $phase = '';
	protected string $state = '{}';
	protected ?string $stats = null;
	protected ?string $error = null;
	protected ?string $snapshotId = null;
	protected int $startedAt = 0;
	protected int $updatedAt = 0;
	protected ?int $finishedAt = null;

	public function __construct() {
		$this->addType('targetId', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('finishedAt', 'integer');
	}
}
