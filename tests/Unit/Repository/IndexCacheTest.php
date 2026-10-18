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
			public function putMany(string $r, array $packs): void { foreach ($packs as $p => $e) { $this->put($r, (string)$p, $e); } }
			public function replaceAll(string $r, array $packs): void { $this->data[$r] = []; $this->putMany($r, $packs); }
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

	/** Connecting a new server to a location with backups reports how far reading the index is. */
	public function testOpeningReportsIndexProgress(): void {
		$src = $this->tempDir();
		for ($i = 0; $i < 6; $i++) {
			file_put_contents("$src/f$i", random_bytes(3 * 1048576));
		}
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'))->backupDirectory($src);
		$files = count($backend->list('index'));
		$seen = [];
		Repository::openWithKey($backend, $keys, null, null, function (int $done, int $total) use (&$seen): void {
			$seen[] = [$done, $total];
		});
		$this->assertCount($files, $seen);
		$this->assertSame([$files, $files], end($seen));
	}

	/** The database dump reads in a read-only transaction: a pack that fills up then must not write the cache yet. */
	public function testHeldCacheWritesWaitForRelease(): void {
		$cache = new class implements IIndexCache {
			public array $data = [];
			public bool $readOnly = false;
			public function all(string $r): array { return $this->data[$r] ?? []; }
			public function put(string $r, string $p, array $e): void {
				if ($this->readOnly) {
					throw new \RuntimeException('cannot execute INSERT in a read-only transaction');
				}
				$this->data[$r][$p] = $e;
			}
			public function remove(string $r, array $ps): void { foreach ($ps as $p) { unset($this->data[$r][$p]); } }
			public function putMany(string $r, array $packs): void { foreach ($packs as $p => $e) { $this->put($r, (string)$p, $e); } }
			public function replaceAll(string $r, array $packs): void { $this->data[$r] = []; $this->putMany($r, $packs); }
		};
		$backend = new LocalBackend($this->tempDir());
		$keys = KeyRing::generate();
		Repository::initWithKey($backend, $keys, $keys->wrap('pw'));
		$repo = Repository::openWithKey($backend, $keys, $cache);

		$stats = [];
		$repo->holdIndexCache();
		$cache->readOnly = true;
		$writer = $repo->blobWriter($stats);
		for ($i = 0; $i < 40; $i++) {
			$writer->write(random_bytes(1048576)); // more than one 32 MiB pack
		}
		$writer->finish();
		$this->assertGreaterThan(0, count($backend->list('index')), 'a full pack was uploaded during the hold');
		$this->assertSame([], $cache->data);

		$cache->readOnly = false;
		$repo->releaseIndexCache();
		$repo->flushPacks();
		$this->assertCount(count($backend->list('index')), array_merge(...array_values($cache->data)));
		$this->assertSame(0, Repository::openWithKey($backend, $keys, $cache)->indexDownloads());
	}

	/** After a check the cache holds every index file as it is, also a blob that is in two packs. */
	public function testACheckCachesTheIndexFilesAsTheyAre(): void {
		$cache = new class implements IIndexCache {
			public array $data = [];
			public int $replaced = 0;
			public function all(string $r): array { return $this->data[$r] ?? []; }
			public function put(string $r, string $p, array $e): void { $this->data[$r][$p] = $e; }
			public function remove(string $r, array $ps): void { foreach ($ps as $p) { unset($this->data[$r][$p]); } }
			public function putMany(string $r, array $packs): void { foreach ($packs as $p => $e) { $this->put($r, (string)$p, $e); } }
			public function replaceAll(string $r, array $packs): void { $this->replaced++; $this->data[$r] = $packs; }
		};
		$backend = new LocalBackend($this->tempDir());
		$cipher = new \OCA\NgBackup\Crypto\StreamCipher(KeyRing::generate());
		$index = new \OCA\NgBackup\Repository\BlobIndex($backend, $cipher);
		$index->setCache($cache, 'r');
		$blob = static fn (string $id, int $offset): array => ['id' => $id, 'offset' => $offset, 'length' => 10, 'raw' => 8, 'flags' => 0];
		$index->addPack('pack1', [$blob('a', 0), $blob('shared', 10)]);
		$index->addPack('pack2', [$blob('shared', 0), $blob('b', 10)]);
		$cache->data = ['r' => ['gone' => [$blob('x', 0)]]];

		$index->load(true);
		$this->assertSame(1, $cache->replaced);
		$this->assertSame(['pack1', 'pack2'], array_keys($cache->data['r']));
		$this->assertCount(2, $cache->data['r']['pack1'], 'the shared blob stays in the row of its first pack');

		// the second pack goes: the shared blob is still found in the first
		$index->removePack('pack2');
		$again = new \OCA\NgBackup\Repository\BlobIndex($backend, $cipher);
		$again->setCache($cache, 'r');
		$again->load();
		$this->assertSame(0, $again->downloaded);
		$this->assertSame('pack1', $again->get('shared')[0]);
	}
}
