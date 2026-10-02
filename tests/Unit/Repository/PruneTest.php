<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class PruneTest extends TestCase {
	use TempDirTrait;

	private function du(LocalBackend $b): int {
		$root = (new \ReflectionProperty(LocalBackend::class, 'root'))->getValue($b);
		$total = 0;
		foreach ($b->list('packs') as $p) {
			$total += filesize("$root/$p");
		}
		return $total;
	}

	public function testForgetAndPruneFreesSpaceAndKeepsTheRest(): void {
		$src = $this->tempDir();
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		$repo = Repository::initWithKey($backend, $keys, $keys->wrap('pw'));

		file_put_contents("$src/keep.bin", random_bytes(6 * 1048576));
		file_put_contents("$src/old.bin", random_bytes(40 * 1048576));
		$old = $repo->backupDirectory($src);
		unlink("$src/old.bin");
		file_put_contents("$src/new.bin", random_bytes(3 * 1048576));
		$new = $repo->backupDirectory($src, $old['snapshot']);
		$expected = self::hashTree($src);
		$before = $this->du($backend);

		$dry = $repo->prune(true);
		$this->assertSame(0, $dry['freedBytes'], 'nothing is unused while both snapshots exist');
		$preview = $repo->prune(true, 0.5, [$old['snapshot']]);
		$this->assertGreaterThan(35 * 1048576, $preview['freedBytes'], 'dry run can preview forgetting a snapshot');

		$repo->forget($old['snapshot']);
		$plan = $repo->prune(true);
		$this->assertSame($plan, $repo->prune(true, 0.5, []));
		$this->assertGreaterThan(35 * 1048576, $plan['freedBytes']);
		$this->assertSame($before, $this->du($backend), 'dry run changes nothing');

		$result = $repo->prune();
		$this->assertGreaterThan(0, $result['deleted'] + $result['repacked']);
		$this->assertLessThan($before - 35 * 1048576, $this->du($backend));

		$reopened = Repository::openWithKey($backend, $keys);
		$target = $this->tempDir();
		$reopened->restore($new['snapshot'], $target);
		$this->assertSame($expected, self::hashTree($target));
		$this->assertSame([$new['snapshot']], $reopened->listSnapshots());
	}

	public function testRepackKeepsUsedBlobsOfMixedPacks(): void {
		$src = $this->tempDir();
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		$repo = Repository::initWithKey($backend, $keys, $keys->wrap('pw'));
		// Small files share packs: deleting most of them leaves mixed packs to repack.
		for ($i = 0; $i < 40; $i++) {
			file_put_contents("$src/f$i", random_bytes(500000));
		}
		$a = $repo->backupDirectory($src);
		for ($i = 0; $i < 40; $i++) {
			if ($i % 5 !== 0) {
				unlink("$src/f$i");
			}
		}
		$b = $repo->backupDirectory($src, $a['snapshot']);
		$repo->forget($a['snapshot']);
		$r = $repo->prune(false, 0.5);
		$this->assertGreaterThan(0, $r['repacked']);
		$target = $this->tempDir();
		Repository::openWithKey($backend, $keys)->restore($b['snapshot'], $target);
		$this->assertSame(self::hashTree($src), self::hashTree($target));
	}
}
