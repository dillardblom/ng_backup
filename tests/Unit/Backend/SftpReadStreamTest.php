<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

use OCA\NgBackup\Backend\BackendException;
use OCA\NgBackup\Backend\SftpReadStream;
use PHPUnit\Framework\TestCase;

/**
 * SftpReadStream against a fake of the phpseclib connection: an SFTP server that answers READ
 * requests from in-memory files, optionally capped. Every answer must be asked for by its request
 * id, as phpseclib matches them: a server may answer out of order.
 */
class SftpReadStreamTest extends TestCase {
	protected function setUp(): void {
		SftpReadStream::reset();
	}

	public function testReadsAWholeFileWithSeveralRequestsInFlight(): void {
		$data = random_bytes(5 * 1048576 + 12345);
		$sftp = new FakeSftpConnection(['/home/repo/packs/ab/abcd' => $data]);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'repo/packs/ab/abcd');

		$this->assertIsResource($fh);
		$this->assertSame($data, stream_get_contents($fh));
		$this->assertTrue(feof($fh));
		fclose($fh);
		$this->assertSame(SftpReadStream::DEPTH, $sftp->maxInFlight);
		$this->assertSame(0, $sftp->pending(), 'nothing may be left on the connection after close');
		$this->assertSame([], $sftp->openHandles);
	}

	public function testAServerThatCapsAReadStillGivesEveryByteInOrder(): void {
		$data = random_bytes(3 * 1048576 + 7);
		$sftp = new FakeSftpConnection(['/home/f' => $data], cap: 100000);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');

		$this->assertSame($data, stream_get_contents($fh));
		fclose($fh);
		$this->assertSame(0, $sftp->pending());

		// The cap is learned: a next stream asks for no more than the server gives.
		$sftp->reads = [];
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');
		$this->assertSame($data, stream_get_contents($fh));
		fclose($fh);
		$this->assertSame([100000], array_values(array_unique($sftp->reads)));

		// Only for that connection.
		$other = new FakeSftpConnection(['/home/f' => $data]);
		$fh = SftpReadStream::open(new FakeSftpStorage($other), 'f');
		$this->assertSame($data, stream_get_contents($fh));
		fclose($fh);
		$this->assertSame(261120, max($other->reads));
	}

	public function testATinyAnswerDoesNotShrinkTheReadsBelowWhatEveryServerSupports(): void {
		$data = random_bytes(1048576);
		$sftp = new FakeSftpConnection(['/home/f' => $data], cap: 1000);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');
		$this->assertSame($data, stream_get_contents($fh));
		fclose($fh);
		$this->assertSame(32768, min($sftp->reads));
	}

	public function testSeekingReadsFromTheNewOffset(): void {
		$data = random_bytes(3 * 1048576);
		$sftp = new FakeSftpConnection(['/home/f' => $data]);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');

		$this->assertSame(0, fseek($fh, 2000000));
		$this->assertSame(2000000, ftell($fh));
		$this->assertSame(substr($data, 2000000, 1000), fread($fh, 1000));
		$this->assertSame(0, fseek($fh, 10)); // backwards
		$this->assertSame(substr($data, 10, 70000), $this->readExactly($fh, 70000));
		$this->assertSame(0, fseek($fh, 100, SEEK_CUR)); // within what is already buffered
		$this->assertSame(substr($data, 70110, 5000), $this->readExactly($fh, 5000));
		fclose($fh);
		$this->assertSame(0, $sftp->pending());
	}

	public function testASmallReadDoesNotFetchTheWholePipelineAhead(): void {
		$sftp = new FakeSftpConnection(['/home/f' => random_bytes(10 * 1048576)]);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');
		fread($fh, 100);
		fclose($fh);
		$this->assertCount(1, $sftp->reads, 'the first request goes alone, the pipeline grows while reading on');
	}

	public function testAnEmptyFileIsAtEndAtOnce(): void {
		$sftp = new FakeSftpConnection(['/home/f' => '']);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');
		$this->assertSame('', stream_get_contents($fh));
		$this->assertTrue(feof($fh));
		fclose($fh);
	}

	public function testAMissingFileCannotBeOpened(): void {
		$sftp = new FakeSftpConnection([]);
		$this->assertFalse(SftpReadStream::open(new FakeSftpStorage($sftp), 'nope'));
		$this->assertSame(0, $sftp->pending());
	}

	public function testAServerErrorGoesOnWithTheStoragesOwnStreamFromTheSamePosition(): void {
		$data = random_bytes(3 * 1048576);
		$sftp = new FakeSftpConnection(['/home/f' => $data], failAt: 1048576);
		$storage = new FakeSftpStorage($sftp);
		$fh = SftpReadStream::open($storage, 'f');

		$this->assertSame($data, $this->readAll($fh));
		$this->assertSame(['f'], $storage->fopens);
		$this->assertSame(strlen($data), ftell($fh));
		fclose($fh);
		$this->assertSame(0, $sftp->pending(), 'the connection is clean for the storage\'s own stream');
		$this->assertSame([], $sftp->openHandles, 'the failed handle is closed');

		// One failure is no reason to give up on the fast reads.
		$fh = SftpReadStream::open($storage, 'f');
		$this->assertIsResource($fh);
		fclose($fh);
	}

	public function testOnlyFailuresInARowSwitchTheFastReadsOff(): void {
		$data = random_bytes(2 * 1048576);
		$failing = new FakeSftpStorage(new FakeSftpConnection(['/home/f' => $data], failAt: 1048576));
		$working = new FakeSftpStorage(new FakeSftpConnection(['/home/f' => $data]));
		for ($i = 1; $i < SftpReadStream::MAX_FAILURES; $i++) {
			$fh = SftpReadStream::open($failing, 'f');
			$this->assertSame($data, $this->readAll($fh));
			fclose($fh);
		}
		$fh = SftpReadStream::open($working, 'f'); // a stream that works resets the count
		$this->assertSame($data, $this->readAll($fh));
		fclose($fh);
		for ($i = 1; $i <= SftpReadStream::MAX_FAILURES; $i++) {
			$fh = SftpReadStream::open($failing, 'f');
			$this->assertIsResource($fh, "failure $i");
			$this->assertSame($data, $this->readAll($fh));
			fclose($fh);
		}
		$this->assertNull(SftpReadStream::open($working, 'f'));
		$this->assertSame(SftpReadStream::MAX_FAILURES * 2 - 1, count($failing->fopens), 'every failed stream still gave all its data');
	}

	public function testAServerErrorAfterASeekGoesOnFromTheSeekedPosition(): void {
		$data = random_bytes(3 * 1048576);
		$sftp = new FakeSftpConnection(['/home/f' => $data], failAt: 2500000);
		$storage = new FakeSftpStorage($sftp);
		$fh = SftpReadStream::open($storage, 'f');
		$this->assertSame(0, fseek($fh, 2400000));
		$this->assertSame(substr($data, 2400000), $this->readAll($fh));
		$this->assertSame(0, fseek($fh, 100)); // seeks go to the storage's stream now
		$this->assertSame(substr($data, 100, 1000), $this->readExactly($fh, 1000));
		fclose($fh);
	}

	public function testAConnectionErrorDoesNotFallBackOnTheSameConnection(): void {
		// After an exception, answers may still be on their way; files_external's stream would
		// take them for its own.
		$sftp = new FakeSftpConnection(['/home/f' => random_bytes(3 * 1048576)], throwAt: 1048576);
		$storage = new FakeSftpStorage($sftp);
		$fh = SftpReadStream::open($storage, 'f');
		$this->assertLessThan(3 * 1048576, strlen($this->readAll($fh)));
		fclose($fh);
		$this->assertSame([], $storage->fopens);
	}

	public function testALostConnectionDoesNotFallBackEither(): void {
		$sftp = new FakeSftpConnection(['/home/f' => random_bytes(3 * 1048576)], loseAt: 1048576);
		$storage = new FakeSftpStorage($sftp);
		$fh = SftpReadStream::open($storage, 'f');
		$this->assertLessThan(3 * 1048576, strlen($this->readAll($fh)));
		fclose($fh);
		$this->assertSame([], $storage->fopens);
	}

	public function testWhenTheStoragesOwnStreamFailsTooTheReadFails(): void {
		$sftp = new FakeSftpConnection(['/home/f' => random_bytes(3 * 1048576)], failAt: 1048576);
		$storage = new FakeSftpStorage($sftp, fopenFails: true);
		$fh = SftpReadStream::open($storage, 'f');
		$this->assertLessThan(3 * 1048576, strlen($this->readAll($fh)));
		fclose($fh);
		$this->assertSame(['f'], $storage->fopens);
	}

	public function testAConnectionErrorWhileOpeningIsAnErrorNotAMissingFile(): void {
		// The OPEN request went out: the storage's own stream must not be tried on this connection.
		$storage = new FakeSftpStorage(new FakeSftpConnection(['/home/f' => 'x'], throwAt: -1));
		try {
			SftpReadStream::open($storage, 'f');
			$this->fail('expected a BackendException');
		} catch (BackendException $e) {
			$this->assertStringContainsString('connection', $e->getMessage());
		}
		$lost = new FakeSftpStorage(new FakeSftpConnection(['/home/f' => 'x'], loseAt: -1));
		$this->expectException(BackendException::class);
		SftpReadStream::open($lost, 'f');
	}

	public function testAConnectionErrorWhileOpeningDoesNotStopTheNextFile(): void {
		try {
			SftpReadStream::open(new FakeSftpStorage(new FakeSftpConnection(['/home/f' => 'x'], throwAt: -1)), 'f');
		} catch (BackendException) {
		}
		$fh = SftpReadStream::open(new FakeSftpStorage(new FakeSftpConnection(['/home/f' => 'x'])), 'f');
		$this->assertSame('x', stream_get_contents($fh));
		fclose($fh);
	}

	public function testAFailedCloseDoesNotFallBackOnTheSameConnection(): void {
		$sftp = new FakeSftpConnection(['/home/f' => random_bytes(3 * 1048576)], failAt: 1048576, closeFails: true);
		$storage = new FakeSftpStorage($sftp);
		$fh = SftpReadStream::open($storage, 'f');
		$this->assertLessThan(3 * 1048576, strlen($this->readAll($fh)));
		fclose($fh);
		$this->assertSame([], $storage->fopens);
	}

	public function testAStorageThatThrowsIsNotUsed(): void {
		$storage = new class {
			public function getRoot(): string {
				return '/';
			}

			public function getConnection(): object {
				throw new \RuntimeException('Login failed');
			}
		};
		$this->assertNull(SftpReadStream::open($storage, 'f'));
	}

	public function testAConnectionWithoutTheNeededMethodsIsNotUsed(): void {
		$storage = new class {
			public function getRoot(): string {
				return '/';
			}

			public function getConnection(): object {
				return new \stdClass();
			}
		};
		$this->assertNull(SftpReadStream::open($storage, 'f'));
		$this->assertNull(SftpReadStream::open(new \stdClass(), 'f'));
	}

	public function testAPhpseclib3ConnectionIsNotUsed(): void {
		// phpseclib 3 has no _-prefixed methods; its packet_type and packet methods are private.
		$v3 = new class {
			private int $packet_type = -1;

			private function _send_sftp_packet(): bool {
				return true;
			}

			private function _get_sftp_packet(): string {
				return '';
			}

			public function _realpath(string $path): string {
				return $path;
			}

			public function realpath(string $path): string {
				return $path . (string)$this->packet_type . $this->_send_sftp_packet() . $this->_get_sftp_packet();
			}
		};
		$storage = new class($v3) {
			public function __construct(
				private object $connection,
			) {
			}

			public function getRoot(): string {
				return '/';
			}

			public function getConnection(): object {
				return $this->connection;
			}
		};
		$this->assertNull(SftpReadStream::open($storage, 'f'));
	}

	public function testAPrivatePacketTypeIsNotUsed(): void {
		$conn = new class {
			private int $packet_type = -1;

			public function _send_sftp_packet(): bool {
				return true;
			}

			public function _get_sftp_packet(): string {
				return (string)$this->packet_type;
			}

			public function _realpath(string $path): string {
				return $path;
			}
		};
		$storage = new class($conn) {
			public function __construct(
				private object $connection,
			) {
			}

			public function getRoot(): string {
				return '/';
			}

			public function getConnection(): object {
				return $this->connection;
			}
		};
		$this->assertNull(SftpReadStream::open($storage, 'f'));
	}

	/** @param resource $fh */
	private function readAll($fh): string {
		$data = '';
		while (!feof($fh)) {
			$chunk = fread($fh, 65536);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		return $data;
	}

	/** @param resource $fh */
	private function readExactly($fh, int $length): string {
		$data = '';
		while (strlen($data) < $length && !feof($fh)) {
			$data .= fread($fh, $length - strlen($data));
		}
		return $data;
	}
}

final class FakeSftpStorage {
	/** @var list<string> paths opened through the storage's own stream */
	public array $fopens = [];

	public function __construct(
		private FakeSftpConnection $connection,
		private bool $fopenFails = false,
	) {
	}

	/** files_external's own read stream: here the file from memory, seekable. */
	public function fopen(string $path, string $mode) {
		$this->fopens[] = $path;
		$data = $this->connection->file('/home/' . $path);
		if ($this->fopenFails || $data === null) {
			return false;
		}
		$fh = fopen('php://memory', 'r+');
		fwrite($fh, $data);
		rewind($fh);
		return $fh;
	}

	public function getRoot(): string {
		return '/home/';
	}

	public function getConnection(): FakeSftpConnection {
		return $this->connection;
	}
}

/** The part of phpseclib\Net\SFTP (2.x) that SftpReadStream uses. */
final class FakeSftpConnection {
	public int $packet_type = -1;
	public int $maxInFlight = 0;
	/** @var list<int> lengths asked for by READ requests */
	public array $reads = [];
	/** @var array<string, string> */
	public array $openHandles = [];
	/** @var array<int, array{int, string}> answers by request id, in the order the server sent them */
	private array $answers = [];
	private int $handles = 0;

	/** @param array<string, string> $files */
	public function __construct(
		private array $files,
		private int $cap = 261120,
		private ?int $failAt = null,
		/** READ at or past this offset throws; -1: OPEN throws */
		private ?int $throwAt = null,
		/** READ at or past this offset finds the connection gone (phpseclib returns false); -1: OPEN */
		private ?int $loseAt = null,
		private bool $closeFails = false,
	) {
	}

	public function file(string $path): ?string {
		return $this->files[$path] ?? null;
	}

	public function pending(): int {
		return count($this->answers);
	}

	public function _realpath(string $path): string {
		return '/' . trim(preg_replace('#/+#', '/', $path), '/');
	}

	public function _send_sftp_packet(int $type, string $data, int $id = 1): bool {
		switch ($type) {
			case 3: // OPEN
				if ($this->throwAt === -1) {
					throw new \RuntimeException('Connection closed by server');
				}
				if ($this->loseAt === -1) {
					return false;
				}
				$path = substr($data, 4, unpack('N', $data)[1]);
				if (!isset($this->files[$path])) {
					$this->answers[$id] = [101, pack('N', 2)];
					break;
				}
				$handle = 'h' . ++$this->handles;
				$this->openHandles[$handle] = $path;
				$this->answers[$id] = [102, pack('Na*', strlen($handle), $handle)];
				break;
			case 4: // CLOSE
				if ($this->closeFails) {
					return false;
				}
				$handle = substr($data, 4, unpack('N', $data)[1]);
				unset($this->openHandles[$handle]);
				$this->answers[$id] = [101, pack('N', 0)];
				break;
			case 5: // READ
				$len = unpack('N', $data)[1];
				$handle = substr($data, 4, $len);
				['hi' => $hi, 'lo' => $lo, 'n' => $n] = unpack('Nhi/Nlo/Nn', substr($data, 4 + $len));
				$offset = $hi * 4294967296 + $lo;
				$this->reads[] = $n;
				if ($this->throwAt !== null && $this->throwAt >= 0 && $offset >= $this->throwAt) {
					throw new \RuntimeException('Connection closed by server');
				}
				if ($this->loseAt !== null && $this->loseAt >= 0 && $offset >= $this->loseAt) {
					$this->answers = [];
					return false;
				}
				$file = $this->files[$this->openHandles[$handle]];
				if ($this->failAt !== null && $offset >= $this->failAt) {
					$this->answers[$id] = [101, pack('N', 4)]; // FX_FAILURE
				} elseif ($offset >= strlen($file)) {
					$this->answers[$id] = [101, pack('N', 1)]; // FX_EOF
				} else {
					$chunk = substr($file, $offset, min($n, $this->cap));
					$this->answers[$id] = [103, pack('Na*', strlen($chunk), $chunk)];
				}
				$this->maxInFlight = max($this->maxInFlight, count($this->answers));
				break;
			default:
				throw new \LogicException("unexpected packet type $type");
		}
		return true;
	}

	public function _get_sftp_packet(?int $id = null): string|false {
		if ($this->answers === []) {
			throw new \LogicException('waiting for an answer that will never come');
		}
		if ($id === null || !isset($this->answers[$id])) {
			throw new \LogicException("no answer for request $id");
		}
		[$this->packet_type, $payload] = $this->answers[$id];
		unset($this->answers[$id]);
		return $payload;
	}
}
