<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/**
 * A pack stream that hands out wrong bytes once (an SMB restore stopped after 17 hours with
 * "Decryption failed"): the blob is read once more on a request of its own; only data that is
 * damaged on the location stops the restore.
 */
class DamagedReadTest extends TestCase {
	use TempDirTrait;

	private string $root;
	private KeyRing $keys;
	private string $src;
	private string $snapshot;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->keys = KeyRing::generate();
		$this->src = $this->tempDir();
		for ($i = 0; $i < 6; $i++) {
			file_put_contents("$this->src/f$i", random_bytes(3 * 1048576));
		}
		$repo = Repository::initWithKey(new LocalBackend($this->root), $this->keys, $this->keys->wrap('pw'));
		$this->snapshot = $repo->backupDirectory($this->src)['snapshot'];
	}

	public function testABlobThatCameInDamagedOnceIsReadAgain(): void {
		$backend = new GarblingBackend(new LocalBackend($this->root), streams: 1);
		$target = $this->tempDir();

		$this->assertSame(6, Repository::openWithKey($backend, $this->keys)->restore($this->snapshot, $target));

		$this->assertSame(self::hashTree($this->src), self::hashTree($target));
		$this->assertSame(0, $backend->streams, 'the damaged read happened');
	}

	public function testATableDumpThatCameInDamagedOnceIsReadAgain(): void {
		$repo = Repository::openWithKey(new LocalBackend($this->root), $this->keys);
		$stats = [];
		$writer = $repo->blobWriter($stats);
		$lines = [];
		for ($i = 0; $i < 20000; $i++) {
			$lines[] = json_encode(['i' => $i, 'pad' => str_repeat('x', $i % 400)]);
		}
		$writer->write(implode("\n", $lines) . "\n");
		$blobs = $writer->finish();
		$repo->flushPacks();
		$backend = new GarblingBackend(new LocalBackend($this->root), ranges: 1);

		$this->assertSame($lines, iterator_to_array(Repository::openWithKey($backend, $this->keys)->readLines($blobs), false));
		$this->assertSame(0, $backend->ranges, 'the damaged read happened');
	}

	public function testDataDamagedOnTheLocationStillStopsTheRestore(): void {
		$backend = new GarblingBackend(new LocalBackend($this->root), streams: 1000, ranges: 1000);

		$this->expectException(\Exception::class);
		Repository::openWithKey($backend, $this->keys)->restore($this->snapshot, $this->tempDir());
	}
}

/** Pack reads that flip a byte: the first $streams pack streams and $ranges range reads. */
final class GarblingBackend implements IBackend {
	use \OCA\NgBackup\Backend\GetsOneByOne;

	public function __construct(
		private IBackend $inner,
		public int $streams = 0,
		public int $ranges = 0,
	) {
	}

	public function put(string $path, $stream): void {
		$this->inner->put($path, $stream);
	}

	public function get(string $path) {
		$fh = $this->inner->get($path);
		if (!str_starts_with($path, 'packs/') || $this->streams <= 0) {
			return $fh;
		}
		$this->streams--;
		$data = stream_get_contents($fh);
		fclose($fh);
		for ($k = 100; $k < strlen($data); $k += 65536) {
			$data[$k] = chr(ord($data[$k]) ^ 0x55);
		}
		$out = fopen('php://temp', 'w+b');
		fwrite($out, $data);
		rewind($out);
		return $out;
	}

	public function getRange(string $path, int $offset, int $length): string {
		$data = $this->inner->getRange($path, $offset, $length);
		if (str_starts_with($path, 'packs/') && $this->ranges > 0) {
			$this->ranges--;
			$data[0] = chr(ord($data[0]) ^ 0x55);
		}
		return $data;
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
