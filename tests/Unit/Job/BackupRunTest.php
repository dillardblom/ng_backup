<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Job;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
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
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));

		$state = BackupRun::start($src);
		$steps = 0;
		while ($state['phase'] !== 'commit' && $steps < 500) {
			// Fresh repository object per step, state through JSON: as separate processes would.
			$repo = Repository::openWithKey($backend, $keys);
			$state = json_decode(json_encode(BackupRun::step($repo, $state, microtime(true) + 0.05)), true);
			$steps++;
		}
		$this->assertSame('commit', $state['phase']);
		Repository::openWithKey($backend, $keys)->recordSnapshot($state['snapshot']);
		$this->assertGreaterThan(2, $steps, 'the run was actually split into steps');

		$target = $this->tempDir();
		Repository::openWithKey($backend, $keys)->restore($state['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree($target));
	}

	public function testTheSnapshotIsWrittenInAStepOfItsOwnFromAllSegmentsAtOnce(): void {
		$src = $this->tempDir();
		for ($i = 0; $i < 60; $i++) {
			file_put_contents("$src/f$i.txt", random_bytes(200000));
		}
		$backend = new RangeCountingBackend(new LocalBackend($this->tempDir()));
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));

		$state = BackupRun::start($src);
		for ($i = 0; $i < 500 && $state['phase'] === 'files'; $i++) {
			$state = BackupRun::step(Repository::openWithKey($backend, $keys), $state, microtime(true) + 0.01);
		}
		$this->assertSame('finish', $state['phase'], 'the step that read the last file does not write the snapshot');
		$this->assertGreaterThan(2, count($state['segments']));
		$backend->batches = [];

		$state = BackupRun::step(Repository::openWithKey($backend, $keys), $state, microtime(true) + 0.01);

		$this->assertSame('commit', $state['phase']);
		// One batch; the blobs of a segment follow each other in its pack and are one read.
		$this->assertSame([count($state['segments'])], $backend->batches);
		Repository::openWithKey($backend, $keys)->recordSnapshot($state['snapshot']);
		$target = $this->tempDir();
		Repository::openWithKey($backend, $keys)->restore($state['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree($target));
	}

	public function testBlobsThatFollowEachOtherInAPackAreReadAsOneRange(): void {
		$backend = new RangeCountingBackend(new LocalBackend($this->tempDir()));
		$keys = KeyRing::generate();
		$repo = Repository::initWithKey($backend, $keys, $keys->wrap('pw'));
		$stats = [];
		$segments = [];
		$expected = [];
		for ($s = 0; $s < 3; $s++) {
			$writer = new \OCA\NgBackup\Repository\BlobWriter(fn (string $data): string => $repo->storeData($data, $stats), 1000);
			$lines = [];
			for ($i = 0; $i < 100; $i++) {
				$lines[] = json_encode(['segment' => $s, 'line' => $i, 'pad' => bin2hex(random_bytes(20))]);
			}
			$writer->write(implode("\n", $lines) . "\n");
			$segments[] = $writer->finish();
			$repo->flushPacks(); // one pack per segment, as one per step
			array_push($expected, ...$lines);
		}
		$this->assertGreaterThan(3, count(array_merge(...$segments)));
		$backend->batches = [];

		$read = iterator_to_array(Repository::openWithKey($backend, $keys)->readLines(array_merge(...$segments)), false);

		$this->assertSame($expected, array_values(array_filter($read, fn (string $l): bool => $l !== '')));
		$this->assertSame([3], $backend->batches, 'one read per segment, all in one batch');
	}

	public function testFileChangedBetweenStepsIsReadAgain(): void {
		$src = $this->tempDir();
		file_put_contents("$src/big.bin", random_bytes(20 * 1048576));
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));

		$state = BackupRun::start($src);
		$mutated = false;
		for ($i = 0; $i < 500 && $state['phase'] !== 'commit'; $i++) {
			$state = BackupRun::step(Repository::openWithKey($backend, $keys), $state, microtime(true) + 0.01);
			if (!$mutated && $state['cur'] !== null && $state['cur']['offset'] > 0) {
				$fh = fopen("$src/big.bin", 'r+b');
				fwrite($fh, random_bytes(1024)); // same size, new content at the start
				fclose($fh);
				touch("$src/big.bin", time() + 10);
				$mutated = true;
			}
		}
		$this->assertSame('commit', $state['phase']);
		Repository::openWithKey($backend, $keys)->recordSnapshot($state['snapshot']);
		$this->assertTrue($mutated);
		$this->assertGreaterThanOrEqual(1, $state['stats']['retried'] ?? 0);
		$target = $this->tempDir();
		Repository::openWithKey($backend, $keys)->restore($state['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree($target));
	}

	public function testIncrementalRunInTinyStepsReadsNothing(): void {
		$src = $this->tempDir();
		for ($i = 0; $i < 200; $i++) {
			@mkdir("$src/d" . ($i % 7), 0700, true);
			file_put_contents("$src/d" . ($i % 7) . "/f$i", random_bytes(3000));
		}
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));
		$run = function (?string $parent) use ($backend, $src, $keys): array {
			$state = BackupRun::start(['data' => $src], $parent);
			for ($i = 0; $i < 2000 && $state['phase'] !== 'commit'; $i++) {
				$state = json_decode(json_encode(BackupRun::step(Repository::openWithKey($backend, $keys), $state, microtime(true))), true);
			}
			if ($state['phase'] === 'commit') {
				Repository::openWithKey($backend, $keys)->recordSnapshot($state['snapshot']);
			}
			return $state;
		};
		$first = $run(null);
		$second = $run($first['snapshot']);
		$this->assertSame('commit', $second['phase']);
		$this->assertGreaterThan(150, $second["steps"], "one file per step");
		$this->assertSame(0, $second['stats']['read'], 'merge-join with the parent across steps reuses every file');
		$this->assertSame(200, $second['stats']['reused']);
		$target = $this->tempDir();
		Repository::openWithKey($backend, $keys)->restore($second['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree("$target/data"));
	}
}

final class RangeCountingBackend implements \OCA\NgBackup\Backend\IBackend {
	use \OCA\NgBackup\Backend\GetsOneByOne {
		getRanges as private oneByOne;
	}

	/** @var list<int> number of ranges per getRanges() call */
	public array $batches = [];

	public function __construct(
		private \OCA\NgBackup\Backend\IBackend $inner,
	) {
	}

	public function getRanges(array $ranges): \Generator {
		$this->batches[] = count($ranges);
		return $this->oneByOne($ranges);
	}

	public function put(string $path, $stream): void {
		$this->inner->put($path, $stream);
	}

	public function get(string $path) {
		return $this->inner->get($path);
	}

	public function getRange(string $path, int $offset, int $length): string {
		return $this->inner->getRange($path, $offset, $length);
	}

	public function exists(string $path): bool {
		return $this->inner->exists($path);
	}

	public function move(string $from, string $to): void {
		$this->inner->move($from, $to);
	}

	public function list(string $prefix): array {
		return $this->inner->list($prefix);
	}

	public function listFiles(string $dir): array {
		return $this->inner->listFiles($dir);
	}

	public function delete(string $path): void {
		$this->inner->delete($path);
	}
}
