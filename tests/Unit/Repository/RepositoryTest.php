<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\CryptoException;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Repository\RepositoryException;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase {
	use TempDirTrait;

	private string $src;
	private LocalBackend $backend;

	protected function setUp(): void {
		$this->src = $this->tempDir();
		$this->backend = new LocalBackend($this->tempDir());
		for ($i = 0; $i < 30; $i++) {
			$this->write("docs/d" . ($i % 3) . "/note-$i.txt", str_repeat("regel $i ", 500));
		}
		$this->write('bin/random.bin', random_bytes(9 * 1048576 + 123)); // spans 3 blobs
		$this->write('copies/a.bin', $same = random_bytes(5 * 1048576));
		$this->write('copies/b.bin', $same);
		$this->write('empty.txt', '');
	}

	private function write(string $rel, string $data): void {
		@mkdir(dirname($this->src . '/' . $rel), 0700, true);
		file_put_contents($this->src . '/' . $rel, $data);
	}

	public function testBackupAndRestoreAreIdentical(): void {
		$repo = Repository::init($this->backend, 'pw');
		$r = $repo->backupDirectory($this->src);
		$this->assertSame(34, $r['files']);
		$this->assertGreaterThanOrEqual(2, $r['dupBlobs'], 'identical copies are stored once');

		$target = $this->tempDir();
		Repository::open($this->backend, 'pw')->restore($r['snapshot'], $target);
		$this->assertSame(self::hashTree($this->src), self::hashTree($target));
	}

	public function testUnchangedRunReadsAndUploadsNothing(): void {
		$repo = Repository::init($this->backend, 'pw');
		$r1 = $repo->backupDirectory($this->src);
		$r2 = $repo->backupDirectory($this->src, $r1['snapshot']);
		$this->assertSame(0, $r2['read']);
		$this->assertSame(0, $r2['uploaded']);
	}

	public function testChangeInLargeFileUploadsOnlyAffectedBlob(): void {
		$repo = Repository::init($this->backend, 'pw');
		$r1 = $repo->backupDirectory($this->src);
		$fh = fopen($this->src . '/bin/random.bin', 'r+b');
		fseek($fh, 5 * 1048576);
		fwrite($fh, 'changed');
		fclose($fh);
		touch($this->src . '/bin/random.bin', time() + 10);
		$r2 = $repo->backupDirectory($this->src, $r1['snapshot']);
		$this->assertSame(1, $r2['read']);
		$this->assertSame(1, $r2['newBlobs']);
	}

	public function testPartialRestore(): void {
		$repo = Repository::init($this->backend, 'pw');
		$r = $repo->backupDirectory($this->src);
		$target = $this->tempDir();
		$this->assertSame(10, $repo->restore($r['snapshot'], $target, 'docs/d1/'));
	}

	public function testWrongPassphrase(): void {
		Repository::init($this->backend, 'pw');
		$this->expectException(CryptoException::class);
		Repository::open($this->backend, 'other');
	}

	public function testInitRefusesExistingRepository(): void {
		Repository::init($this->backend, 'pw');
		$this->expectException(RepositoryException::class);
		Repository::init($this->backend, 'pw');
	}

	public function testTamperedPackIsDetected(): void {
		$repo = Repository::init($this->backend, 'pw');
		$r = $repo->backupDirectory($this->src);
		$root = (new \ReflectionProperty(LocalBackend::class, 'root'))->getValue($this->backend);
		$pack = $root . '/' . $this->backend->list('packs')[0];
		$bytes = file_get_contents($pack);
		$bytes[200] = chr(ord($bytes[200]) ^ 0xff);
		file_put_contents($pack, $bytes);
		$this->expectException(\RuntimeException::class);
		$repo->restore($r['snapshot'], $this->tempDir());
	}

	public function testBlobWriterAndReadLinesAcrossBlobBoundaries(): void {
		$repo = Repository::init($this->backend, 'pw');
		$stats = [];
		$writer = $repo->blobWriter($stats);
		$lines = [];
		for ($i = 0; $i < 20000; $i++) {
			$lines[] = json_encode(['i' => $i, 'pad' => str_repeat('x', $i % 400)]);
		}
		$writer->write(implode("\n", $lines) . "\n");
		$blobs = $writer->finish();
		$repo->flushPacks();
		$this->assertGreaterThan(1, count($blobs));
		$this->assertSame($lines, iterator_to_array(Repository::open($this->backend, 'pw')->readLines($blobs), false));
	}

	public function testObjectsAreBoundToTheirPath(): void {
		$repo = Repository::init($this->backend, 'pw');
		$repo->putObject('db/1', 'one');
		$root = (new \ReflectionProperty(LocalBackend::class, 'root'))->getValue($this->backend);
		copy("$root/db/1", "$root/db/2");
		$this->expectException(CryptoException::class);
		$repo->getObject('db/2');
	}
}
