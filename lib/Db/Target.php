<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $v)
 * @method string getBackend()
 * @method void setBackend(string $v)
 * @method string getAuth()
 * @method void setAuth(string $v)
 * @method string getOptions()
 * @method void setOptions(string $v)
 * @method string getBasePath()
 * @method void setBasePath(string $v)
 * @method string|null getRepositoryId()
 * @method void setRepositoryId(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class Target extends Entity {
	protected string $name = '';
	protected string $backend = '';
	protected string $auth = '';
	protected string $options = '';
	protected string $basePath = '';
	protected ?string $repositoryId = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('createdAt', 'integer');
	}
}
