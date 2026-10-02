<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\ICatalogAnchor;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Repository\RepositoryException;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

class CatalogTest extends TestCase {
	use TempDirTrait;

	private LocalBackend $backend;
	private KeyRing $keys;
	private ICatalogAnchor $anchor;
	private string $root;
	private string $src;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->backend = new LocalBackend($this->root);
		$this->keys = KeyRing::generate();
		$this->anchor = new class implements ICatalogAnchor {
			public array $data = [];
			public function get(string $r): ?array { return $this->data[$r] ?? null; }
			public function set(string $r, int $g, string $h): void { $this->data[$r] = ['gen' => $g, 'hash' => $h]; }
		};
		$this->src = $this->tempDir();
		file_put_contents("$this->src/a", random_bytes(100000));
	}

	private function open(): Repository {
		return Repository::openWithKey($this->backend, $this->keys, null, $this->anchor);
	}

	public function testNormalUseAdvancesTheChain(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$s1 = $repo->backupDirectory($this->src);
		file_put_contents("$this->src/b", 'x');
		$s2 = $repo->backupDirectory($this->src, $s1['snapshot']);
		$repo->forget($s1['snapshot']);
		$again = $this->open();
		$this->assertSame([$s2['snapshot']], $again->listSnapshots());
		$this->assertArrayHasKey($s1['snapshot'], $again->trash());
		$this->assertSame(4, $again->catalogGeneration());
	}

	public function testRollbackIsDetected(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$repo->backupDirectory($this->src);
		$repo->backupDirectory($this->src);
		// The location "loses" the newest generation and presents an older, validly encrypted state.
		$catalogs = $this->backend->list('catalog');
		unlink($this->root . '/' . end($catalogs));
		$this->expectException(RepositoryException::class);
		$this->expectExceptionMessage('rolled back');
		$this->open();
	}

	public function testHiddenSnapshotIsDetected(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$s = $repo->backupDirectory($this->src);
		unlink($this->root . '/snapshots/' . $s['snapshot']);
		$this->expectException(RepositoryException::class);
		$this->open()->listSnapshots();
	}

	public function testSwappedGenerationIsRejected(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$repo->backupDirectory($this->src);
		$repo->backupDirectory($this->src);
		$c = $this->backend->list('catalog');
		// Put generation 2's bytes where generation 3 should be.
		copy($this->root . '/' . $c[1], $this->root . '/' . $c[2]);
		$this->expectException(\RuntimeException::class);
		$this->open();
	}

	public function testWithoutAnchorTheNewestChainIsAccepted(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'));
		$s = $repo->backupDirectory($this->src);
		// Disaster recovery on a new server: opened with the passphrase, no anchor.
		$this->assertSame([$s['snapshot']], Repository::open($this->backend, 'pw')->listSnapshots());
	}
}
