<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Job;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Job\BackupRun;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class BackupRunTest extends TestCase {
	use TempDirTrait;

	public function testRunInManyTinyStepsRestoresIdentically(): void {
		$src = $this->tempDir();
		mkdir("$src/a", 0700, true);
		for ($i = 0; $i < 50; $i++) {
			file_put_contents("$src/a/f$i.txt", random_bytes(20000));
		}
		file_put_contents("$src/big.bin", random_bytes(13 * 1048576));
		$backend = new LocalBackend($this->tempDir());
		Repository::init($backend, 'pw');

		$state = BackupRun::start($src);
		$steps = 0;
		while ($state['phase'] !== 'done' && $steps < 500) {
			// Fresh repository object per step, state through JSON: as separate processes would.
			$repo = Repository::open($backend, 'pw');
			$state = json_decode(json_encode(BackupRun::step($repo, $state, microtime(true) + 0.05)), true);
			$steps++;
		}
		$this->assertSame('done', $state['phase']);
		$this->assertGreaterThan(2, $steps, 'the run was actually split into steps');

		$target = $this->tempDir();
		Repository::open($backend, 'pw')->restore($state['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree($target));
	}

	public function testFileChangedBetweenStepsIsReadAgain(): void {
		$src = $this->tempDir();
		file_put_contents("$src/big.bin", random_bytes(20 * 1048576));
		$backend = new LocalBackend($this->tempDir());
		Repository::init($backend, 'pw');

		$state = BackupRun::start($src);
		$mutated = false;
		for ($i = 0; $i < 500 && $state['phase'] !== 'done'; $i++) {
			$state = BackupRun::step(Repository::open($backend, 'pw'), $state, microtime(true) + 0.01);
			if (!$mutated && $state['cur'] !== null && $state['cur']['offset'] > 0) {
				$fh = fopen("$src/big.bin", 'r+b');
				fwrite($fh, random_bytes(1024)); // same size, new content at the start
				fclose($fh);
				touch("$src/big.bin", time() + 10);
				$mutated = true;
			}
		}
		$this->assertTrue($mutated);
		$this->assertGreaterThanOrEqual(1, $state['stats']['retried'] ?? 0);
		$target = $this->tempDir();
		Repository::open($backend, 'pw')->restore($state['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree($target));
	}
}
