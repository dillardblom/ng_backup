<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\IIndexCache;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class IndexCacheTest extends TestCase {
	use TempDirTrait;

	public function testSecondOpenDownloadsNoIndexFiles(): void {
		$cache = new class implements IIndexCache {
			public array $data = [];
			public function all(string $r): array { return $this->data[$r] ?? []; }
			public function put(string $r, string $p, array $e): void { $this->data[$r][$p] = $e; }
			public function remove(string $r, array $ps): void { foreach ($ps as $p) { unset($this->data[$r][$p]); } }
		};
		$src = $this->tempDir();
		for ($i = 0; $i < 12; $i++) {
			file_put_contents("$src/f$i", random_bytes(3 * 1048576));
		}
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));

		$repo = Repository::openWithKey($backend, $keys, $cache);
		$r1 = $repo->backupDirectory($src);
		$this->assertGreaterThan(0, count($backend->list('index')));

		$again = Repository::openWithKey($backend, $keys, $cache);
		$this->assertSame(0, $again->indexDownloads(), 'everything came from the cache');

		file_put_contents("$src/new", random_bytes(2 * 1048576));
		$again->backupDirectory($src, $r1['snapshot']);
		$third = Repository::openWithKey($backend, $keys, $cache);
		$this->assertSame(0, $third->indexDownloads(), 'packs written by this server are cached on write');

		// A cache that misses entries (e.g. another server wrote packs) fetches only those.
		$repoId = array_key_first($cache->data);
		array_pop($cache->data[$repoId]);
		$fourth = Repository::openWithKey($backend, $keys, $cache);
		$this->assertSame(1, $fourth->indexDownloads());

		$target = $this->tempDir();
		$fourth->restore($r1['snapshot'], $target);
		$this->assertCount(12, self::hashTree($target));
	}
}
