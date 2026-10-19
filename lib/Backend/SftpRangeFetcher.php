<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/**
 * Reads byte ranges of files on an SFTP location ahead of use, over several connections at once,
 * into local files (StorageBackend::readAhead(), for a full restore).
 *
 * A restore that reads its packs as it goes is bound by the round trip: from a Hetzner Storage
 * Box to Canada (RTT 122 ms) it reached about 2.8 MiB/s, with the PHP process mostly waiting.
 * Here every connection reads its own range with DEPTH requests in flight, ahead of the restore. PHP runs one thing at a time: while it waits for an answer on one connection, the
 * answers of the others arrive in the kernel's buffers, so the connections still transfer at the
 * same time.
 *
 * Uses the same internal phpseclib 2 methods as SftpReadStream (see SftpReadStream::connection()).
 * The connections are this class's own, never the storage's: no other request goes over them.
 * A range that fails (missing file, server error, a connection that breaks) comes out as null,
 * for the caller to read the usual way; a broken connection is not used again.
 */
final class SftpRangeFetcher {
	/**
	 * Connections a full restore uses, unless the app setting restore_sftp_connections says
	 * otherwise. One: a Hetzner Storage Box gave about 14 MiB/s on one connection with DEPTH
	 * requests in flight, and 4-6 MiB/s on 2 or 4 (it reads from disks, and parallel readers make
	 * them seek). On a line with packet loss to a server that handles parallel reads well, 4
	 * connections did better (21 against 14 MiB/s).
	 */
	public const DEFAULT_CONNECTIONS = 1;
	public const MAX_CONNECTIONS = 8;
	/** READ requests in flight per connection. */
	public const DEPTH = 32;
	/** Ranges read ahead of the one the caller is at, in flight or waiting in the spool. */
	public const AHEAD = 8;
	/**
	 * Seconds ready() waits for any connection to answer before it waits for each in turn: short,
	 * in case a connection holds an answer where ready() does not look.
	 */
	private const SELECT_TIMEOUT = 1;
	/** See SftpReadStream::CHUNK and MIN_CHUNK. */
	private const CHUNK = 261120;
	private const MIN_CHUNK = 32768;

	private const FXP_OPEN = 3;
	private const FXP_CLOSE = 4;
	private const FXP_READ = 5;
	private const FXP_STATUS = 101;
	private const FXP_HANDLE = 102;
	private const FXP_DATA = 103;
	private const FXF_READ = 1;
	private const FX_OK = 0;

	private const OPEN = 0;
	private const READ = 1;
	private const CLOSE = 2;

	/** @var array<int, int> READ size per connection, lowered when a server answers less than asked */
	private array $chunks = [];
	/** @var array<int, SftpRangeJob> the range each busy connection reads */
	private array $jobs = [];
	/** @var array<int, resource|null> ranges read (or failed) and not yet given to the caller */
	private array $done = [];
	private int $requestId = 0x4e520000;

	/**
	 * @param array<int, object> $connections phpseclib 2 connections with their SFTP channel open
	 * @param list<array{0:string, 1:int, 2:int}> $ranges paths below $root
	 */
	private function __construct(
		private array $connections,
		private string $root,
		private array $ranges,
		private string $spool,
	) {
		foreach (array_keys($connections) as $c) {
			$this->chunks[$c] = self::CHUNK;
		}
	}

	/**
	 * See IReadsAhead::readAhead(). The connections are ended when the generator ends.
	 *
	 * @param list<object> $connections
	 * @param list<array{0:string, 1:int, 2:int}> $ranges
	 * @return \Generator<int, resource|null>
	 */
	public static function fetch(array $connections, string $root, array $ranges, string $spool): \Generator {
		$fetcher = new self($connections, rtrim($root, '/'), $ranges, $spool);
		try {
			yield from $fetcher->run();
		} finally {
			$fetcher->end();
		}
	}

	/** @return \Generator<int, resource|null> */
	private function run(): \Generator {
		$count = count($this->ranges);
		$next = 0;
		for ($want = 0; $want < $count; $want++) {
			while (!array_key_exists($want, $this->done)) {
				foreach (array_keys($this->connections) as $c) {
					if (!isset($this->jobs[$c]) && $next < $count && $next < $want + self::AHEAD) {
						$this->start($c, $next++);
					}
				}
				if ($this->jobs === [] && !array_key_exists($want, $this->done)) {
					// no connection left: the rest the usual way
					$this->done[$want] = null;
					$next = max($next, $want + 1);
					break;
				}
				foreach ($this->ready() as $c) {
					$this->step($c);
				}
			}
			$fh = $this->done[$want];
			unset($this->done[$want]);
			try {
				yield $want => $fh;
			} finally {
				if (is_resource($fh)) {
					fclose($fh);
				}
			}
		}
	}

	/**
	 * The busy connections that have an answer waiting, so that a connection that waits for a
	 * lost packet to be sent again does not hold up the others; all of them when the connections
	 * do not tell (then they are taken in turn).
	 *
	 * @return list<int>
	 */
	private function ready(): array {
		$sockets = [];
		$ready = [];
		foreach ($this->jobs as $c => $job) {
			$sftp = $this->connections[$c];
			$vars = get_object_vars($sftp); // only what is public: phpseclib 2's are
			// Answers phpseclib has read already: the socket does not show them. Its request
			// buffer holds whole answers read while it waited for another; its packet buffer and
			// channel buffers hold what came in with the last packet it read (small answers, such
			// as a CLOSE and the next OPEN, often arrive together).
			$buffered = $vars['requestBuffer'] ?? null;
			$channels = $vars['channel_buffers'] ?? null;
			if ((is_array($buffered) && isset($job->queue[0]) && array_key_exists($job->queue[0][0], $buffered))
				|| (is_string($vars['packet_buffer'] ?? null) && self::wholePacket($vars['packet_buffer']))
				|| (is_array($channels) && array_filter($channels) !== [])) {
				$ready[] = $c;
				continue;
			}
			$socket = $vars['fsock'] ?? null;
			if (!is_resource($socket)) {
				return array_keys($this->jobs);
			}
			$sockets[$c] = $socket;
		}
		if ($ready !== [] || $sockets === []) {
			return $ready;
		}
		$read = $sockets;
		$write = null;
		$except = null;
		// Data in PHP's own buffer of a socket counts as readable. Nothing within the time:
		// each in turn, where phpseclib's own timeout applies.
		$n = @stream_select($read, $write, $except, self::SELECT_TIMEOUT);
		return $n === false || $n === 0 ? array_keys($this->jobs) : array_keys($read);
	}

	/** Whether $buffer starts with a whole SFTP packet (length, then that many bytes). */
	private static function wholePacket(string $buffer): bool {
		$length = strlen($buffer) >= 4 ? unpack('N', $buffer) : false;
		return is_array($length) && strlen($buffer) >= 4 + $length[1];
	}

	private function start(int $c, int $i): void {
		[$path, $offset, $length] = $this->ranges[$i];
		$fh = $this->spoolFile();
		if ($fh === null) {
			$this->done[$i] = null;
			return;
		}
		if ($length <= 0) {
			$this->done[$i] = $fh;
			return;
		}
		$this->jobs[$c] = new SftpRangeJob($i, $fh, $offset, $offset + $length);
		try {
			$remote = $this->connections[$c]->_realpath($this->root . '/' . ltrim($path, '/'));
			if (!is_string($remote)) {
				$this->finish($c);
				return;
			}
			$this->send($c, self::FXP_OPEN, pack('Na*N2', strlen($remote), $remote, self::FXF_READ, 0), self::OPEN);
		} catch (\Throwable) {
			$this->drop($c);
		}
	}

	/** Take the oldest answer on connection $c, then keep its requests in flight. */
	private function step(int $c): void {
		$job = $this->jobs[$c];
		[$id, $kind, $offset, $length] = array_shift($job->queue) ?? throw new \LogicException('no request in flight');
		$sftp = $this->connections[$c];
		try {
			$response = $sftp->_get_sftp_packet($id);
			if (!is_string($response)) {
				throw new BackendException('SFTP connection lost');
			}
			$type = $sftp->packet_type;
			if ($kind === self::OPEN) {
				if ($type === self::FXP_HANDLE) {
					$job->handle = substr($response, 4);
				} else {
					$job->failed = true; // missing, or no permission
				}
			} elseif ($kind === self::READ) {
				if (!$job->failed) {
					$this->received($c, $type, $response, $offset, $length);
				}
			} else {
				$status = $type === self::FXP_STATUS && strlen($response) >= 4 ? unpack('N', $response) : false;
				$closed = is_array($status) && $status[1] === self::FX_OK;
				$this->finish($c);
				if (!$closed) {
					// handles might pile up on the server: not used again
					$this->drop($c);
				}
				return;
			}
			$this->refill($c);
		} catch (\Throwable) {
			$this->drop($c);
		}
	}

	private function received(int $c, int $type, string $response, int $offset, int $length): void {
		$job = $this->jobs[$c];
		$declared = strlen($response) >= 4 ? unpack('N', $response) : false;
		$n = is_array($declared) ? $declared[1] : 0;
		if ($type !== self::FXP_DATA || $n <= 0 || $n !== strlen($response) - 4 || $n > $length) {
			// end of file before the end of the range, a server error or a malformed answer
			$job->failed = true;
			return;
		}
		if (fseek($job->fh, $offset - $job->start) !== 0 || fwrite($job->fh, substr($response, 4)) !== $n) {
			$job->failed = true;
			return;
		}
		$job->got += $n;
		if ($n < $length) {
			// a server that caps a READ: ask for the rest, and no more than it gives from now on
			$job->redo[] = [$offset + $n, $length - $n];
			$this->chunks[$c] = max(self::MIN_CHUNK, min($this->chunks[$c], $n));
		}
	}

	/** Send READs until DEPTH are in flight; when nothing is left to ask for, close the file. */
	private function refill(int $c): void {
		$job = $this->jobs[$c];
		if ($job->handle !== '' && !$job->failed) {
			while (count($job->queue) < self::DEPTH) {
				if ($job->redo !== []) {
					[$offset, $length] = array_shift($job->redo);
				} elseif ($job->next < $job->end) {
					$offset = $job->next;
					$length = min($this->chunks[$c], $job->end - $offset);
					$job->next += $length;
				} else {
					break;
				}
				$packet = pack('Na*N3', strlen($job->handle), $job->handle, intdiv($offset, 4294967296), $offset % 4294967296, $length);
				$this->send($c, self::FXP_READ, $packet, self::READ, $offset, $length);
			}
		}
		if ($job->queue !== []) {
			return;
		}
		if ($job->handle === '') {
			$this->finish($c); // not opened: nothing to close
			return;
		}
		$this->send($c, self::FXP_CLOSE, pack('Na*', strlen($job->handle), $job->handle), self::CLOSE);
	}

	private function send(int $c, int $type, string $packet, int $kind, int $offset = 0, int $length = 0): void {
		$this->requestId = $this->requestId >= 0x7fffffff ? 0x4e520000 : $this->requestId + 1;
		if (!$this->connections[$c]->_send_sftp_packet($type, $packet, $this->requestId)) {
			throw new BackendException('SFTP connection failed while sending a request');
		}
		$this->jobs[$c]->queue[] = [$this->requestId, $kind, $offset, $length];
	}

	/** The range of connection $c is done: its stream for the caller, or null when it failed. */
	private function finish(int $c): void {
		$job = $this->jobs[$c];
		unset($this->jobs[$c]);
		if ($job->failed || $job->got !== $job->end - $job->start || !rewind($job->fh)) {
			self::closeFile($job->fh);
			$this->done[$job->i] = null;
			return;
		}
		$this->done[$job->i] = $job->fh;
	}

	/** A connection in an unknown state: its range fails, and it is not used again. */
	private function drop(int $c): void {
		if (isset($this->jobs[$c])) {
			$job = $this->jobs[$c];
			unset($this->jobs[$c]);
			self::closeFile($job->fh);
			$this->done[$job->i] = null;
		}
		self::disconnect($this->connections[$c]);
		unset($this->connections[$c]);
	}

	/** @return resource|null a new file in the spool, already unlinked: nothing is left behind if the process dies */
	private function spoolFile() {
		$path = $this->spool . '/' . bin2hex(random_bytes(8));
		$fh = @fopen($path, 'x+b');
		if ($fh === false) {
			return null;
		}
		@unlink($path);
		return $fh;
	}

	private function end(): void {
		foreach ($this->jobs as $job) {
			self::closeFile($job->fh);
		}
		foreach ($this->done as $fh) {
			if (is_resource($fh)) {
				fclose($fh);
			}
		}
		$this->jobs = [];
		$this->done = [];
		foreach ($this->connections as $sftp) {
			self::disconnect($sftp);
		}
		$this->connections = [];
	}

	private static function closeFile(mixed $fh): void {
		if (is_resource($fh)) {
			fclose($fh);
		}
	}

	private static function disconnect(object $sftp): void {
		try {
			if (is_callable([$sftp, 'disconnect'])) {
				$sftp->disconnect();
			}
		} catch (\Throwable) {
		}
	}
}
