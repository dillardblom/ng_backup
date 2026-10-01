<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getTargetId()
 * @method void setTargetId(int $v)
 * @method string getSnapshotId()
 * @method void setSnapshotId(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string|null getLabel()
 * @method void setLabel(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int|null getFiles()
 * @method void setFiles(?int $v)
 * @method int|null getBytes()
 * @method void setBytes(?int $v)
 */
class Snapshot extends Entity {
	protected int $targetId = 0;
	protected string $snapshotId = '';
	protected string $kind = '';
	protected ?string $label = null;
	protected int $createdAt = 0;
	protected ?int $files = null;
	protected ?int $bytes = null;

	public function __construct() {
		$this->addType('targetId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('files', 'integer');
		$this->addType('bytes', 'integer');
	}
}
