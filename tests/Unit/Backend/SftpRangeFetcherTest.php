<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\SftpRangeFetcher;
use OCA\NgBackup\Backend\SftpReadStream;
use OCA\NgBackup\Tests\Unit\TempDirTrait;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SftpReadStreamTest.php'; // FakeSftpConnection, FakeSftpStorage

/** SftpRangeFetcher against the fake SFTP server of SftpReadStreamTest, several connections at once. */
class SftpRangeFetcherTest extends TestCase {
	use TempDirTrait;

	/** @var array<string, string> */
	private array $files = [];

	protected function setUp(): void {
		SftpReadStream::reset();
		for ($i = 0; $i < 10; $i++) {
			$this->files["/home/repo/packs/p$i"] = random_bytes(3 * 1048576 + $i * 1000);
		}
	}

	/** @return list<FakeSftpConnection> */
	private function connections(int $n, array $options = []): array {
		$result = [];
		for ($c = 0; $c < $n; $c++) {
			$result[] = new FakeSftpConnection($this->files, ...($options[$c] ?? []));
		}
		return $result;
	}

	/** @return list<object> what StorageBackend passes on: each with its SFTP channel open */
	private static function opened(array $connections): array {
		return array_map(fn (FakeSftpConnection $c): object => SftpReadStream::connection(new FakeSftpStorage($c)), $connections);
	}

	/** @return list<array{0:string, 1:int, 2:int}> */
	private function ranges(): array {
		$ranges = [];
		for ($i = 0; $i < 10; $i++) {
			$ranges[] = ["repo/packs/p$i", 1000 * $i, 2 * 1048576 + 333];
		}
		return $ranges;
	}

	/** @return array<int, string|null> sha1 of the contents by position (a failing comparison of MiBs would not fit in memory) */
	private static function collect(\Generator $streams): array {
		$result = [];
		foreach ($streams as $i => $fh) {
			$result[$i] = $fh === null ? null : sha1(stream_get_contents($fh));
		}
		return $result;
	}

	private function expected(array $ranges): array {
		return array_map(fn (array $r): string => sha1(substr($this->files['/home/' . $r[0]], $r[1], $r[2])), $ranges);
	}

	public function testReadsEveryRangeInOrderOverAllConnections(): void {
		$connections = $this->connections(4);
		$ranges = $this->ranges();
		$spool = $this->tempDir();

		$this->assertSame($this->expected($ranges), self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $ranges, $spool)));

		foreach ($connections as $c) {
			$this->assertGreaterThan(0, count($c->reads), 'every connection reads');
			$this->assertLessThanOrEqual(SftpRangeFetcher::DEPTH, $c->maxInFlight);
			$this->assertSame([], $c->openHandles);
			$this->assertSame(0, $c->pending());
			$this->assertTrue($c->disconnected, 'its own connections end with it');
		}
		$this->assertSame([], glob("$spool/*"), 'nothing left in the spool');
	}

	public function testKeepsSeveralRequestsInFlightOnEachConnection(): void {
		$this->files['/home/repo/packs/big'] = random_bytes(10 * 1048576);
		$connections = $this->connections(2);
		self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', [['repo/packs/big', 0, 10 * 1048576], ['repo/packs/big', 5, 10 * 1048576 - 5]], $this->tempDir()));

		$this->assertSame(SftpRangeFetcher::DEPTH, $connections[0]->maxInFlight);
		$this->assertSame(SftpRangeFetcher::DEPTH, $connections[1]->maxInFlight);
	}

	public function testAnAnswerPhpseclibHasReadAlreadyIsTakenWithoutWaitingOnTheSocket(): void {
		// The fake's answers are all "read already": its socket never becomes readable, and
		// phpseclib's packet buffer holds data. Waiting on the socket would take a second a step.
		$connections = $this->connections(2);
		$sockets = [];
		foreach ($connections as $c) {
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
			$sockets[] = $pair;
			$c->fsock = $pair[0];
			$c->packet_buffer = pack('N', 4) . 'more'; // a whole packet
		}
		$ranges = array_slice($this->ranges(), 0, 3);
		$start = microtime(true);

		$this->assertSame($this->expected($ranges), self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $ranges, $this->tempDir())));

		$this->assertLessThan(1.0, microtime(true) - $start);
	}

	public function testAServerThatCapsAReadStillGivesEveryByte(): void {
		$connections = $this->connections(3, array_fill(0, 3, ['cap' => 100000]));
		$ranges = $this->ranges();

		$this->assertSame($this->expected($ranges), self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $ranges, $this->tempDir())));
		// learned: after the first short answers (and asking for their rest) it asks for no more than the server gives
		foreach ($connections as $c) {
			$this->assertLessThanOrEqual(2 * SftpRangeFetcher::DEPTH, count(array_filter($c->reads, fn (int $n): bool => $n > 100000)));
		}
	}

	public function testAMissingFileIsLeftToTheCaller(): void {
		$connections = $this->connections(2);
		$ranges = $this->ranges();
		$ranges[3][0] = 'repo/packs/gone';

		$result = self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $ranges, $this->tempDir()));

		$expected = $this->expected($this->ranges());
		$expected[3] = null;
		$this->assertSame($expected, $result);
		$this->assertSame([], $connections[0]->openHandles);
	}

	public function testARangePastTheEndOfTheFileIsLeftToTheCaller(): void {
		$ranges = $this->ranges();
		$ranges[5][2] = 10 * 1048576;

		$result = self::collect(SftpRangeFetcher::fetch(self::opened($this->connections(2)), '/home/', $ranges, $this->tempDir()));

		$this->assertNull($result[5]);
		$this->assertSame($this->expected($this->ranges())[6], $result[6]);
	}

	public function testAServerErrorFailsOnlyThatRange(): void {
		// every read from 1 MiB on fails on connection 0: its ranges, not those of the others
		$connections = $this->connections(2, [['failAt' => 1048576]]);
		$ranges = $this->ranges();

		$result = self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $ranges, $this->tempDir()));

		$expected = $this->expected($ranges);
		$failed = array_keys(array_filter($result, fn (?string $r): bool => $r === null));
		$this->assertNotSame([], $failed);
		foreach ($result as $i => $data) {
			if ($data !== null) {
				$this->assertSame($expected[$i], $data);
			}
		}
		$this->assertLessThan(10, count($failed), 'the other connection goes on');
		$this->assertSame([], $connections[0]->openHandles, 'a failed range is closed too');
	}

	public function testALostConnectionIsNotUsedAgainAndTheOthersGoOn(): void {
		$connections = $this->connections(3, [['loseAt' => 1048576]]);
		$ranges = $this->ranges();

		$result = self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $ranges, $this->tempDir()));

		$expected = $this->expected($ranges);
		$this->assertSame(1, count(array_filter($result, fn (?string $r): bool => $r === null)), 'only the range it was reading');
		foreach ($result as $i => $data) {
			if ($data !== null) {
				$this->assertSame($expected[$i], $data);
			}
		}
		$this->assertTrue($connections[0]->disconnected);
		$this->assertLessThanOrEqual(1048576 / 261120 + SftpRangeFetcher::DEPTH + 1, count($connections[0]->reads), 'not used after it broke');
	}

	public function testWhenEveryConnectionIsLostTheRestIsLeftToTheCaller(): void {
		$connections = $this->connections(2, [['throwAt' => 1048576], ['loseAt' => 1048576]]);

		$result = self::collect(SftpRangeFetcher::fetch(self::opened($connections), '/home/', $this->ranges(), $this->tempDir()));

		$this->assertSame(array_fill(0, 10, null), $result);
	}

	public function testStoppingHalfwayEndsTheConnectionsAndLeavesNothingBehind(): void {
		$connections = $this->connections(2);
		$spool = $this->tempDir();
		$streams = SftpRangeFetcher::fetch(self::opened($connections), '/home/', $this->ranges(), $spool);
		$this->assertIsResource($streams->current());

		unset($streams);

		$this->assertTrue($connections[0]->disconnected);
		$this->assertTrue($connections[1]->disconnected);
		$this->assertSame([], glob("$spool/*"));
	}

	public function testReadsNoMoreThanAheadOfTheCaller(): void {
		$connections = $this->connections(4);
		$streams = SftpRangeFetcher::fetch(self::opened($connections), '/home/', $this->ranges(), $this->tempDir());
		$streams->current();

		$opened = array_sum(array_map(fn (FakeSftpConnection $c): int => count(array_unique(array_values($c->opened))), $connections));
		$this->assertLessThanOrEqual(SftpRangeFetcher::AHEAD, $opened);
	}
}
