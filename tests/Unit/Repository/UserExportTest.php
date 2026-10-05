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

/**
 * Repository::recordUserExport/listUserExports/forgetUserExport: the catalog side of a
 * user_migration export (see RepositoryExportDestination/RepositoryImportSource, which this
 * does not exercise directly since they implement OCP\UserMigration interfaces not available to
 * plain PHPUnit; that round trip is covered by tests/integration/user-roundtrip.sh instead).
 */
class UserExportTest extends TestCase {
	use TempDirTrait;

	private LocalBackend $backend;
	private KeyRing $keys;
	private ICatalogAnchor $anchor;
	private string $root;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->backend = new LocalBackend($this->root);
		$this->keys = KeyRing::generate();
		$this->anchor = new class implements ICatalogAnchor {
			public array $data = [];
			public function get(string $r): ?array { return $this->data[$r] ?? null; }
			public function set(string $r, int $g, string $h): void { $this->data[$r] = ['gen' => $g, 'hash' => $h]; }
		};
	}

	private function open(): Repository {
		return Repository::openWithKey($this->backend, $this->keys, null, $this->anchor);
	}

	/** Stands in for an export destination's close(): a manifest object plus a catalog record. */
	private function putManifest(Repository $repo, string $path): void {
		$repo->putObject($path, json_encode(['uid' => 'alice']));
		$repo->recordUserExport($path);
	}

	public function testRecordAndListSurviveAReopen(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$this->putManifest($repo, 'users/alice/20261005T120000Z-aaaa0001');

		$again = $this->open();
		$this->assertSame(['users/alice/20261005T120000Z-aaaa0001'], $again->listUserExports());
		$this->assertSame(['users/alice/20261005T120000Z-aaaa0001'], $again->listUserExports('alice'));
		$this->assertSame([], $again->listUserExports('bob'));
	}

	public function testListIsNewestFirst(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$this->putManifest($repo, 'users/alice/20261005T120000Z-aaaa0001');
		$this->putManifest($repo, 'users/alice/20261006T120000Z-aaaa0002');

		$this->assertSame(
			['users/alice/20261006T120000Z-aaaa0002', 'users/alice/20261005T120000Z-aaaa0001'],
			$repo->listUserExports('alice'),
		);
	}

	public function testForgetRemovesItFromTheCatalogAndLocation(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$path = 'users/alice/20261005T120000Z-aaaa0001';
		$this->putManifest($repo, $path);

		$repo->forgetUserExport($path);

		$this->assertSame([], $repo->listUserExports());
		$this->assertFalse($this->backend->exists($path));
	}

	public function testForgetRejectsAPathNotInTheCatalog(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$this->expectException(RepositoryException::class);
		$repo->forgetUserExport('users/nobody/not-a-real-export');
	}

	public function testIsRecordedUserExport(): void {
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$path = 'users/alice/20261005T120000Z-aaaa0001';
		$this->putManifest($repo, $path);

		$this->assertTrue($repo->isRecordedUserExport($path));
		$this->assertFalse($repo->isRecordedUserExport('users/alice/not-the-real-one'));
	}

	public function testListingDetectsAManifestRemovedBehindItsBack(): void {
		// A catalog-listed export whose object disappeared from the location (deleted or
		// hidden outside NG Backup) must be detected, the same as a missing snapshot.
		$repo = Repository::initWithKey($this->backend, $this->keys, $this->keys->wrap('pw'), $this->anchor);
		$path = 'users/alice/20261005T120000Z-aaaa0001';
		$this->putManifest($repo, $path);
		$this->backend->delete($path);

		$this->expectException(RepositoryException::class);
		$this->expectExceptionMessage('missing on the location');
		$repo->listUserExports();
	}
}
