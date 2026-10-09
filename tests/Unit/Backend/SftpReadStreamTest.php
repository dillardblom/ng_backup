<?php

declare(strict_types=1);

namespace OCA\NgBackup\Tests\Unit\Backend;

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

	public function testAServerErrorFailsTheReadAndLeavesTheConnectionUsable(): void {
		$sftp = new FakeSftpConnection(['/home/f' => random_bytes(3 * 1048576), '/home/g' => 'second'], failAt: 1048576);
		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'f');
		$read = '';
		while (!feof($fh)) {
			$chunk = fread($fh, 65536);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$read .= $chunk;
		}
		$this->assertLessThan(3 * 1048576, strlen($read));
		fclose($fh);
		$this->assertSame(0, $sftp->pending());

		$fh = SftpReadStream::open(new FakeSftpStorage($sftp), 'g');
		$this->assertSame('second', stream_get_contents($fh));
		fclose($fh);
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
	public function __construct(
		private FakeSftpConnection $connection,
	) {
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
	) {
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
