<?php

declare(strict_types=1);

// Stand-in for the files_external SMB storage in unit tests: only what StorageBackend uses.

namespace OCA\Files_External\Lib\Storage;

use OCP\Files\Storage\IStorage;

if (!class_exists(SMB::class)) {
	abstract class SMB implements IStorage {
		/** @var object */
		protected $share;
		protected bool $showHidden = false;
		protected bool $checkAcl = false;

		protected function buildPath(string $path): string {
			return '/' . trim($path, '/');
		}
	}
}
