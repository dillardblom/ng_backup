<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit;

trait TempDirTrait {
	/** @var list<string> */
	private array $tempDirs = [];

	protected function tempDir(): string {
		$dir = sys_get_temp_dir() . '/ngb-test-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700, true);
		$this->tempDirs[] = $dir;
		return $dir;
	}

	protected function tearDown(): void {
		foreach ($this->tempDirs as $dir) {
			exec('rm -rf ' . escapeshellarg($dir));
		}
		$this->tempDirs = [];
		parent::tearDown();
	}

	/** @return array<string, string> relative path => sha256 */
	protected static function hashTree(string $root): array {
		$out = [];
		if (!is_dir($root)) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if ($f->isFile()) {
				$out[substr($f->getPathname(), strlen($root) + 1)] = hash_file('sha256', $f->getPathname());
			}
		}
		ksort($out);
		return $out;
	}
}
