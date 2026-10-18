<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Backend\IReadsAhead;
use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\ReadAheadPlan;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PackReadTest.php'; // PackOpenCountingBackend

/** A full restore reads the packs ahead (IReadsAhead) and falls back to the usual reads per range. */
class ReadAheadTest extends TestCase {
	use TempDirTrait;

	private string $root;
	private KeyRing $keys;
	private string $src;
	private string $snapshot;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->keys = KeyRing::generate();
		$this->src = $this->tempDir();
		// three packs of 32 MiB, and small files in between
		for ($i = 0; $i < 4; $i++) {
			file_put_contents(sprintf('%s/big%d', $this->src, $i), random_bytes(20 * 1048576));
			for ($j = 0; $j < 20; $j++) {
				file_put_contents(sprintf('%s/small%d-%02d', $this->src, $i, $j), random_bytes(30000));
			}
		}
		file_put_contents("$this->src/same", file_get_contents("$this->src/small0-00")); // the same blob again, later
		$repo = Repository::initWithKey(new LocalBackend($this->root), $this->keys, $this->keys->wrap('pw'));
		$this->snapshot = $repo->backupDirectory($this->src)['snapshot'];
	}

	private function restore(ReadAheadBackend $backend, string $prefix = ''): string {
		$target = $this->tempDir();
		$repo = Repository::openWithKey($backend, $this->keys, null, null);
		$repo->restore($this->snapshot, $target, $prefix);
		return $target;
	}

	private function assertRestored(string $target, string $prefix = ''): void {
		$expected = array_filter(self::hashTree($this->src), fn (string $p): bool => str_starts_with($p, $prefix), ARRAY_FILTER_USE_KEY);
		$this->assertSame($expected, self::hashTree($target));
		$this->assertSame([], glob("$target/.ngb-restore-spool-*"), 'the spool is removed');
	}

	public function testARestoreReadsEveryPackAhead(): void {
		$backend = new ReadAheadBackend(new LocalBackend($this->root));

		$this->assertRestored($this->restore($backend));

		$this->assertSame(0, $backend->counter->packOpens(), 'nothing read the usual way');
		$this->assertGreaterThanOrEqual(3, count($backend->asked));
		// in the order of the files, one range per pack the restore goes through (the same blob
		// again later is a range of its own)
		$this->assertLessThanOrEqual(count($backend->inner->list('packs')) + 1, count($backend->asked));
	}

	public function testARangeThatCouldNotBeReadAheadIsReadTheUsualWay(): void {
		$backend = new ReadAheadBackend(new LocalBackend($this->root), fail: [1]);

		$this->assertRestored($this->restore($backend));

		$this->assertGreaterThan(0, $backend->counter->packOpens());
	}

	public function testADamagedCopyIsReadAgainTheUsualWay(): void {
		$backend = new ReadAheadBackend(new LocalBackend($this->root), damage: [0, 2]);

		$this->assertRestored($this->restore($backend));

		$this->assertGreaterThan(0, $backend->counter->packOpens());
	}

	public function testAPartialRestoreReadsOnlyWhatItNeeds(): void {
		$backend = new ReadAheadBackend(new LocalBackend($this->root));

		$this->assertRestored($this->restore($backend, 'big1'), 'big1');

		$this->assertSame(0, $backend->counter->packOpens());
		$this->assertCount(2, $backend->asked, 'the end of one pack and the start of the next');
		$total = array_sum(array_map(fn (array $r): int => $r[2], $backend->asked));
		$this->assertLessThan(21 * 1048576, $total, 'not the whole packs');
	}

	public function testABackendThatCannotReadAheadNowRestoresAsBefore(): void {
		$backend = new ReadAheadBackend(new LocalBackend($this->root), unavailable: true);

		$this->assertRestored($this->restore($backend));

		$this->assertGreaterThan(0, $backend->counter->packOpens());
	}

	public function testAFailingRestoreStillEndsTheReadsAheadAndRemovesTheSpool(): void {
		// the second range fails, and so does reading it the usual way
		$backend = new ReadAheadBackend(new LocalBackend($this->root), fail: [1], packsGone: true);
		$target = $this->tempDir();
		$repo = Repository::openWithKey($backend, $this->keys, null, null);

		try {
			$repo->restore($this->snapshot, $target);
			$this->fail('restore should fail');
		} catch (BackendException $e) {
			$this->assertStringStartsWith('Not found', $e->getMessage());
		}

		$this->assertTrue($backend->ended, 'the generator was ended');
		$this->assertSame([], glob("$target/.ngb-restore-spool-*"));
	}

	public function testPlanJoinsBlobsThatFollowEachOtherInAPack(): void {
		$plan = new ReadAheadPlan('/nowhere');
		$plan->add('packs/aa/a', 0, 100);
		$plan->add('packs/aa/a', 100, 100);
		$plan->add('packs/aa/a', 50, 10); // the same blob again: inside the range
		$plan->add('packs/aa/a', 200 + ReadAheadPlan::GAP, 100); // a gap it reads along
		$plan->add('packs/aa/a', 300 + 3 * ReadAheadPlan::GAP, 100); // too far: a new range
		$plan->add('packs/bb/b', 0, 100);
		$plan->add('packs/bb/b', 0, 100); // backwards is fine as long as it is inside
		$plan->add('packs/aa/a', 0, 100); // back to an earlier pack: a new range

		$this->assertSame([
			['packs/aa/a', 0, 300 + ReadAheadPlan::GAP],
			['packs/aa/a', 300 + 3 * ReadAheadPlan::GAP, 100],
			['packs/bb/b', 0, 100],
			['packs/aa/a', 0, 100],
		], $plan->ranges());
	}

	public function testPlanSkipsRangesWhoseBlobsWereReadElsewhere(): void {
		$plan = new ReadAheadPlan('/nowhere');
		foreach (range(0, 4) as $p) {
			$plan->add("packs/0$p/p$p", 0, 10);
		}
		$plan->readFrom((function (): \Generator {
			foreach (range(0, 4) as $p) {
				$fh = fopen('php://temp', 'w+b');
				fwrite($fh, str_repeat((string)$p, 10));
				rewind($fh);
				yield $p => $fh;
			}
		})());

		$this->assertSame('00000', $plan->read('packs/00/p0', 0, 5));
		$this->assertNull($plan->read('packs/77/moved', 0, 5), 'moved: not in the plan');
		$this->assertSame('333', $plan->read('packs/03/p3', 2, 3), 'the ranges in between were read elsewhere');
		$this->assertNull($plan->read('packs/01/p1', 0, 5), 'never backwards');
		$this->assertSame('44', $plan->read('packs/04/p4', 0, 2));
	}

	public function testPlanKeepsARangeShorterThanTheMaximum(): void {
		$plan = new ReadAheadPlan('/nowhere');
		for ($o = 0; $o < 3 * ReadAheadPlan::MAX_RANGE; $o += 4 * 1048576) {
			$plan->add('packs/aa/a', $o, 4 * 1048576);
		}
		$this->assertCount(3, $plan->ranges());
		foreach ($plan->ranges() as $r) {
			$this->assertLessThanOrEqual(ReadAheadPlan::MAX_RANGE, $r[2]);
		}
	}
}

/** Reads ahead from a local repository, one range after the other; can fail or damage ranges. */
final class ReadAheadBackend implements IBackend, IReadsAhead {
	public PackOpenCountingBackend $counter;
	/** @var list<array{0:string, 1:int, 2:int}> */
	public array $asked = [];
	public bool $ended = false;

	/**
	 * @param list<int> $fail ranges given as null
	 * @param list<int> $damage ranges given with a flipped byte in every blob
	 */
	public function __construct(
		public LocalBackend $inner,
		private array $fail = [],
		private array $damage = [],
		private bool $unavailable = false,
		/** reading a pack the usual way fails */
		private bool $packsGone = false,
	) {
		$this->counter = new PackOpenCountingBackend($inner);
	}

	public function canReadAhead(): bool {
		return true;
	}

	public function readAhead(array $ranges, string $spool): ?\Generator {
		if ($this->unavailable) {
			return null;
		}
		\PHPUnit\Framework\Assert::assertDirectoryExists($spool);
		$this->asked = $ranges;
		return (function () use ($ranges): \Generator {
			try {
				foreach ($ranges as $i => [$path, $offset, $length]) {
					if (in_array($i, $this->fail, true)) {
						yield $i => null;
						continue;
					}
					$data = $this->inner->getRange($path, $offset, $length);
					if (in_array($i, $this->damage, true)) {
						for ($k = 0; $k < strlen($data); $k += 4096) {
							$data[$k] = chr(ord($data[$k]) ^ 1);
						}
					}
					$fh = fopen('php://temp', 'w+b');
					fwrite($fh, $data);
					rewind($fh);
					yield $i => $fh;
					fclose($fh);
				}
			} finally {
				$this->ended = true;
			}
		})();
	}

	public function put(string $path, $stream): void {
		$this->counter->put($path, $stream);
	}

	public function get(string $path) {
		if ($this->packsGone && str_starts_with($path, 'packs/')) {
			throw new BackendException('Not found: ' . $path);
		}
		return $this->counter->get($path);
	}

	public function getMany(array $paths): \Generator {
		return $this->counter->getMany($paths);
	}

	public function getRange(string $path, int $offset, int $length): string {
		return $this->counter->getRange($path, $offset, $length);
	}

	public function getRanges(array $ranges): \Generator {
		return $this->counter->getRanges($ranges);
	}

	public function exists(string $path): bool {
		return $this->counter->exists($path);
	}

	public function move(string $from, string $to): void {
		$this->counter->move($from, $to);
	}

	public function list(string $prefix): array {
		return $this->counter->list($prefix);
	}

	public function listFiles(string $dir): array {
		return $this->counter->listFiles($dir);
	}

	public function delete(string $path): void {
		$this->counter->delete($path);
	}
}
