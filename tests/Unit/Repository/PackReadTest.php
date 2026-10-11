<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Repository;

use OCA\NgBackup\Backend\IBackend;
use OCA\NgBackup\Backend\LocalBackend;
use OCA\NgBackup\Crypto\KeyRing;
use OCA\NgBackup\Repository\Repository;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

/** Restores and deep verifies read a pack as one stream instead of one request per blob. */
class PackReadTest extends TestCase {
	use TempDirTrait;

	private string $root;
	private KeyRing $keys;
	private string $src;
	private string $snapshot;

	protected function setUp(): void {
		$this->root = $this->tempDir();
		$this->keys = KeyRing::generate();
		$this->src = $this->tempDir();
		for ($i = 0; $i < 200; $i++) {
			file_put_contents(sprintf('%s/f%03d', $this->src, $i), random_bytes(20000));
		}
		$backend = new LocalBackend($this->root);
		$repo = Repository::initWithKey($backend, $this->keys, $this->keys->wrap('pw'));
		$this->snapshot = $repo->backupDirectory($this->src)['snapshot'];
	}

	private function open(PackOpenCountingBackend $backend): Repository {
		return Repository::openWithKey($backend, $this->keys, null, null);
	}

	public function testFullRestoreOpensEachPackOnce(): void {
		$backend = new PackOpenCountingBackend(new LocalBackend($this->root));
		$target = $this->tempDir();

		$this->assertSame(200, $this->open($backend)->restore($this->snapshot, $target));

		for ($i = 0; $i < 200; $i++) {
			$name = sprintf('f%03d', $i);
			$this->assertSame(file_get_contents("$this->src/$name"), file_get_contents("$target/$name"));
		}
		// One pack: one stream instead of 200 requests.
		$this->assertSame(1, $backend->packOpens());
	}

	public function testDeepVerifyOpensEachPackOnce(): void {
		$backend = new PackOpenCountingBackend(new LocalBackend($this->root));

		$result = $this->open($backend)->verify($this->snapshot, deep: true);

		$this->assertSame(200 * 20000, $result['bytesChecked']);
		$this->assertSame(1, $backend->packOpens());
	}

	public function testReadingBackwardsOpensThePackAgain(): void {
		$backend = new PackOpenCountingBackend(new LocalBackend($this->root));
		$repo = $this->open($backend);

		$this->assertSame(1, $repo->restore($this->snapshot, $this->tempDir(), 'f150'));
		$this->assertSame(1, $repo->restore($this->snapshot, $this->tempDir(), 'f050'));

		$this->assertSame(2, $backend->packOpens());
	}

	public function testThePackStreamIsClosedBeforeAnyOtherRequest(): void {
		$backend = new PackOpenCountingBackend(new LocalBackend($this->root));
		$repo = $this->open($backend);
		$this->assertSame(1, $repo->restore($this->snapshot, $this->tempDir(), 'f010'));
		$this->assertTrue($backend->packStreamOpen(), 'kept open for the next read');

		$repo->putObject('objects/after-restore', 'data');

		$this->assertSame([false], $backend->packStreamOpenAtPut);
	}

	public function testAPackStreamThatCannotSeekIsReadUpToTheOffset(): void {
		$backend = new PackOpenCountingBackend(new LocalBackend($this->root), $this->root);
		$target = $this->tempDir();

		$this->assertSame(1, $this->open($backend)->restore($this->snapshot, $target, 'f150'));

		$this->assertSame(file_get_contents("$this->src/f150"), file_get_contents("$target/f150"));
	}

	public function testDeepVerifyOfATruncatedPackStillChecksTheBlobsBeforeTheDamage(): void {
		$packs = (new LocalBackend($this->root))->list('packs');
		$this->assertCount(1, $packs);
		$pack = $this->root . '/' . $packs[0];
		$fh = fopen($pack, 'r+b');
		ftruncate($fh, intdiv(filesize($pack), 2));
		fclose($fh);

		$result = $this->open(new PackOpenCountingBackend(new LocalBackend($this->root)))->verify($this->snapshot, deep: true);

		$this->assertGreaterThan(80 * 20000, $result['bytesChecked']);
		$this->assertNotSame([], $result['unreadable']);
		$this->assertSame([], $result['failed']);
	}
}

final class PackOpenCountingBackend implements IBackend {
	/** @var list<string> */
	public array $gets = [];
	/** @var list<bool> */
	public array $packStreamOpenAtPut = [];
	/** @var resource|null */
	private $lastPackStream = null;

	/** @param ?string $pipeFrom serve packs as pipes (streams that cannot seek) from this local repository root */
	public function __construct(
		private IBackend $inner,
		private ?string $pipeFrom = null,
	) {
	}

	public function packStreamOpen(): bool {
		return is_resource($this->lastPackStream);
	}

	public function put(string $path, $stream): void {
		$this->packStreamOpenAtPut[] = $this->packStreamOpen();
		$this->inner->put($path, $stream);
	}

	public function packOpens(): int {
		return count(array_filter($this->gets, fn (string $p): bool => str_starts_with($p, 'packs/')));
	}

	public function get(string $path) {
		$this->gets[] = $path;
		if (!str_starts_with($path, 'packs/')) {
			return $this->inner->get($path);
		}
		$fh = $this->pipeFrom !== null
			? popen('cat ' . escapeshellarg($this->pipeFrom . '/' . $path), 'r')
			: $this->inner->get($path);
		$this->lastPackStream = $fh;
		return $fh;
	}

	public function getRange(string $path, int $offset, int $length): string {
		$this->gets[] = $path;
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
