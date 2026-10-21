<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\IIndexCache;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/**
 * A listing of the location that comes back empty because a connection failed quietly (an SFTP
 * connection a Storage Box dropped) must not empty the index. In a 1 TB disaster recovery test a
 * pack read failed after 13 hours, the reload of the index got an empty listing, and the restore
 * stopped with "Blob not in index".
 */
class IndexListingTest extends TestCase {
	use TempDirTrait;

	private string $root;
	private KeyRing $keys;
	private string $src;
	private string $snapshot;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->keys = KeyRing::generate();
		$this->src = $this->tempDir();
		for ($i = 0; $i < 12; $i++) {
			file_put_contents("$this->src/f$i", random_bytes(3 * 1048576));
		}
		$repo = Repository::initWithKey(new LocalBackend($this->root), $this->keys, $this->keys->wrap('pw'));
		$this->snapshot = $repo->backupDirectory($this->src)['snapshot'];
	}

	private static function cache(): IIndexCache {
		return indexCache();
	}

	public function testARestoreGoesOnAfterAPackReadFailedAndTheListingCameBackEmpty(): void {
		$backend = new FlakyBackend(new LocalBackend($this->root));
		$repo = Repository::openWithKey($backend, $this->keys);
		$backend->failPackReads = 1;      // the read of a pack fails once
		$backend->emptyIndexListings = 1; // and listing the index right after comes back empty
		$target = $this->tempDir();

		$this->assertSame(12, $repo->restore($this->snapshot, $target));

		$this->assertSame(self::hashTree($this->src), self::hashTree($target));
		$this->assertSame(0, $backend->failPackReads, 'the failure happened');
		$this->assertSame(0, $backend->emptyIndexListings, 'the empty listing was seen');
	}

	public function testAPackThatStaysUnreadableIsStillReportedAsSuch(): void {
		$backend = new FlakyBackend(new LocalBackend($this->root));
		$repo = Repository::openWithKey($backend, $this->keys);
		$backend->failPackReads = 1000;

		try {
			$repo->restore($this->snapshot, $this->tempDir());
			$this->fail('the packs cannot be read');
		} catch (BackendException $e) {
			$this->assertStringStartsWith('Not found: packs/', $e->getMessage());
		}
	}

	public function testACheckWithAnEmptyListingIsAnErrorNotEveryBlobMissing(): void {
		$backend = new FlakyBackend(new LocalBackend($this->root));
		$repo = Repository::openWithKey($backend, $this->keys);
		$backend->dead = true;

		$this->expectException(BackendException::class);
		$this->expectExceptionMessage('listed no index files');
		$repo->verify($this->snapshot);
	}

	public function testRefreshingFetchesOnlyIndexFilesItHasNotLoaded(): void {
		$backend = new FlakyBackend(new LocalBackend($this->root));
		$repo = Repository::openWithKey($backend, $this->keys);
		$index = (new \ReflectionProperty($repo, 'index'))->getValue($repo);
		$index->downloaded = 0;

		$index->refresh();

		$this->assertSame(0, $index->downloaded, 'every index file is loaded already, also those whose blobs are elsewhere too');
	}

	public function testARestoreGoesOnWhenTheLocationCannotBeListedAfterAFailedRead(): void {
		$backend = new FlakyBackend(new LocalBackend($this->root));
		$repo = Repository::openWithKey($backend, $this->keys);
		$backend->failPackReads = 1;
		$backend->dead = true; // listing and config look gone; reading packs works again
		$target = $this->tempDir();

		$this->assertSame(12, $repo->restore($this->snapshot, $target));

		$this->assertSame(self::hashTree($this->src), self::hashTree($target));
	}

	public function testAnEmptyListingThatWasAHiccupIsListedAgain(): void {
		$cache = self::cache();
		$backend = new FlakyBackend(new LocalBackend($this->root));
		Repository::openWithKey($backend, $this->keys, $cache);
		$cached = $cache->data;
		$backend->emptyIndexListings = 1;

		$again = Repository::openWithKey($backend, $this->keys, $cache);

		$this->assertSame($cached, $cache->data);
		$this->assertSame(12, $again->restore($this->snapshot, $this->tempDir()));
	}

	public function testAListingThatStaysEmptyWhileIndexFilesAreThereIsNotTaken(): void {
		$cache = self::cache();
		$backend = new FlakyBackend(new LocalBackend($this->root));
		Repository::openWithKey($backend, $this->keys, $cache);
		$cached = $cache->data;
		$backend->emptyIndexListings = 5; // the config answers, the listing keeps lying

		try {
			Repository::openWithKey($backend, $this->keys, $cache);
			$this->fail('index files are there');
		} catch (BackendException $e) {
			$this->assertStringContainsString('is there', $e->getMessage());
		}
		$this->assertSame($cached, $cache->data);
	}

	public function testARepositoryThatReallyBecameEmptyIsTakenAsEmpty(): void {
		$cache = self::cache();
		$backend = new FlakyBackend(new LocalBackend($this->root));
		Repository::openWithKey($backend, $this->keys, $cache);
		$this->assertNotSame([], array_filter($cache->data));
		// another server forgot the last snapshot and cleaned up: no packs and index files left
		foreach (array_merge($backend->list('index'), $backend->list('packs')) as $path) {
			$backend->delete($path);
		}

		Repository::openWithKey($backend, $this->keys, $cache);

		$this->assertSame([], array_filter($cache->data), 'the stale cache rows are gone');
	}

	public function testOpeningWithAnEmptyListingKeepsTheCachedIndex(): void {
		$cache = self::cache();
		$backend = new FlakyBackend(new LocalBackend($this->root));
		Repository::openWithKey($backend, $this->keys, $cache);
		$cached = $cache->data;
		$this->assertNotSame([], $cached);

		$backend->dead = true;
		try {
			Repository::openWithKey($backend, $this->keys, $cache);
			$this->fail('an empty listing of a repository with an index must not be taken as is');
		} catch (BackendException $e) {
			$this->assertStringContainsString('listed no index files', $e->getMessage());
		}
		$this->assertSame($cached, $cache->data, 'the cache is untouched');
	}
}

/** @internal */
function indexCache(): IIndexCache {
	return new class implements IIndexCache {
		public array $data = [];
		public function all(string $r): array { return $this->data[$r] ?? []; }
		public function put(string $r, string $p, array $e): void { $this->data[$r][$p] = $e; }
		public function remove(string $r, array $ps): void { foreach ($ps as $p) { unset($this->data[$r][$p]); } }
		public function putMany(string $r, array $packs): void { foreach ($packs as $p => $e) { $this->put($r, (string)$p, $e); } }
		public function replaceAll(string $r, array $packs): void { $this->data[$r] = []; $this->putMany($r, $packs); }
	};
}

/** A location whose pack reads fail, and whose index listings come back empty, a set number of times. */
final class FlakyBackend implements IBackend {
	use \OCA\NgBackup\Backend\GetsOneByOne;

	public int $failPackReads = 0;
	public int $emptyIndexListings = 0;
	/** The connection is gone: also the repository config looks missing. */
	public bool $dead = false;

	public function __construct(
		private IBackend $inner,
	) {
	}

	public function put(string $path, $stream): void {
		$this->inner->put($path, $stream);
	}

	public function get(string $path) {
		if (str_starts_with($path, 'packs/') && $this->failPackReads > 0) {
			$this->failPackReads--;
			throw new BackendException('Not found: ' . $path);
		}
		return $this->inner->get($path);
	}

	public function getRange(string $path, int $offset, int $length): string {
		if (str_starts_with($path, 'packs/') && $this->failPackReads > 0) {
			$this->failPackReads--;
			throw new BackendException('Not found: ' . $path);
		}
		return $this->inner->getRange($path, $offset, $length);
	}

	public function exists(string $path): bool {
		return !$this->dead && $this->inner->exists($path);
	}

	public function move(string $from, string $to): void {
		$this->inner->move($from, $to);
	}

	public function list(string $prefix): array {
		return $this->inner->list($prefix);
	}

	public function listFiles(string $dir): array {
		if ($dir === 'index' && $this->dead) {
			return [];
		}
		if ($dir === 'index' && $this->emptyIndexListings > 0) {
			$this->emptyIndexListings--;
			return [];
		}
		return $this->inner->listFiles($dir);
	}

	public function delete(string $path): void {
		$this->inner->delete($path);
	}
}
