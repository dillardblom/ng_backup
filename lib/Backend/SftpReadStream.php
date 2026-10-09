<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/**
 * Read stream for files_external's SFTP storage that keeps several READ requests in flight.
 *
 * files_external's own read stream asks for the next 256 KiB only after the previous one has
 * arrived, so every request costs a full round trip: about 5 MB/s at 47 ms to a Hetzner Storage
 * Box, whatever the line speed. With 8 requests in flight the same connection reached 15 MB/s.
 * phpseclib's get() pipelines too, but only into memory or a local file, and only from offset 0.
 *
 * Talks to the storage's phpseclib connection through the same internal methods files_external's
 * read stream uses (_send_sftp_packet, _get_sftp_packet, _realpath); open() returns null when the
 * connection does not have them, and StorageBackend then falls back to the storage's own stream.
 * Like that stream, it owns the connection while open: no other request may go over the same
 * connection until it is closed (PackReaderGuard takes care of that for the repository).
 */
final class SftpReadStream {
	private const PROTOCOL = 'ngbsftpread';
	/** Requests in flight once a stream reads sequentially; it starts at one and doubles. */
	public const DEPTH = 8;
	/** OpenSSH and the Hetzner Storage Box both answer at most 255 KiB per READ. */
	private const CHUNK = 261120;
	/** Every SFTP server must handle packets of this size (draft-ietf-secsh-filexfer-02, section 3). */
	private const MIN_CHUNK = 32768;

	private const FXP_OPEN = 3;
	private const FXP_CLOSE = 4;
	private const FXP_READ = 5;
	private const FXP_STATUS = 101;
	private const FXP_HANDLE = 102;
	private const FXP_DATA = 103;
	private const FXF_READ = 1;
	private const FX_EOF = 1;

	/**
	 * READ size per connection, lowered when a server answers less than asked and then goes on, so
	 * later streams over that connection ask for no more than it gives.
	 *
	 * @var \WeakMap<object, int>|null
	 */
	private static ?\WeakMap $chunks = null;
	private static int $requestId = 0x4e470000;

	/** @var resource|null set by PHP for stream wrappers */
	public $context;

	/**
	 * The phpseclib connection. PHP creates stream wrappers without constructor arguments; it is
	 * set in stream_open(), and no other method is called when that fails.
	 *
	 * @psalm-suppress PropertyNotSetInConstructor
	 */
	private object $sftp;
	private string $handle = '';
	/** Position of the reader: the first byte of $buffer. */
	private int $pos = 0;
	/** Offset of the next READ to send. */
	private int $next = 0;
	private string $buffer = '';
	/** @var list<array{int, int, int}> request id, offset, length, in the order sent */
	private array $inFlight = [];
	private int $depth = 1;
	private bool $eof = false;
	private bool $failed = false;
	/** Length of the last short answer, until the next answer tells whether it was the end of the file. */
	private ?int $shortAnswer = null;

	/**
	 * @param object $storage files_external's SFTP storage (getConnection(), getRoot())
	 * @return resource|false|null the stream, false when the file cannot be opened, null when the
	 *                             connection does not offer what this stream needs
	 */
	public static function open(object $storage, string $path) {
		if (!method_exists($storage, 'getConnection') || !method_exists($storage, 'getRoot')) {
			return null;
		}
		$sftp = $storage->getConnection();
		if (!is_object($sftp) || !method_exists($sftp, '_send_sftp_packet') || !method_exists($sftp, '_get_sftp_packet')
			|| !method_exists($sftp, '_realpath') || !property_exists($sftp, 'packet_type')) {
			return null;
		}
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		$root = rtrim((string)$storage->getRoot(), '/');
		$context = stream_context_create([self::PROTOCOL => ['session' => $sftp, 'path' => $root . '/' . ltrim($path, '/')]]);
		$fh = @fopen(self::PROTOCOL . '://file', 'r', false, $context);
		return $fh === false ? false : $fh;
	}

	/** Test hook: forget the learned READ sizes. */
	public static function reset(): void {
		self::$chunks = null;
	}

	public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool {
		$options = stream_context_get_options($this->context ?? stream_context_get_default())[self::PROTOCOL] ?? [];
		if (!isset($options['session'], $options['path']) || !is_object($options['session'])) {
			return false;
		}
		$this->sftp = $options['session'];
		$remote = $this->sftp->_realpath((string)$options['path']);
		if (!is_string($remote)) {
			return false;
		}
		$id = self::nextId();
		if (!$this->sftp->_send_sftp_packet(self::FXP_OPEN, pack('Na*N2', strlen($remote), $remote, self::FXF_READ, 0), $id)) {
			return false;
		}
		$response = $this->sftp->_get_sftp_packet($id);
		if ($this->sftp->packet_type !== self::FXP_HANDLE || !is_string($response)) {
			return false;
		}
		$this->handle = substr($response, 4);
		return true;
	}

	public function stream_read(int $count): string|false {
		while (strlen($this->buffer) < $count && !$this->eof) {
			if (!$this->receive()) {
				return false;
			}
		}
		$data = substr($this->buffer, 0, $count);
		$this->buffer = substr($this->buffer, strlen($data));
		$this->pos += strlen($data);
		return $data;
	}

	public function stream_eof(): bool {
		return $this->eof && $this->buffer === '';
	}

	public function stream_tell(): int {
		return $this->pos;
	}

	public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
		if ($whence === SEEK_CUR) {
			$offset += $this->pos;
		} elseif ($whence !== SEEK_SET) {
			return false; // the size is never asked for, to save a round trip
		}
		if ($offset < 0 || $this->failed) {
			return false;
		}
		if ($offset >= $this->pos && $offset - $this->pos <= strlen($this->buffer)) {
			$this->buffer = substr($this->buffer, $offset - $this->pos);
		} else {
			$this->drain();
			$this->buffer = '';
			$this->next = $offset;
			$this->eof = false;
			$this->depth = 1;
			$this->shortAnswer = null;
		}
		$this->pos = $offset;
		return true;
	}

	/** @return array<int|string, int> */
	public function stream_stat(): array {
		return [];
	}

	public function stream_close(): void {
		$this->drain();
		if ($this->handle !== '') {
			$id = self::nextId();
			if ($this->sftp->_send_sftp_packet(self::FXP_CLOSE, pack('Na*', strlen($this->handle), $this->handle), $id)) {
				$this->sftp->_get_sftp_packet($id);
			}
		}
		$this->handle = '';
	}

	/** Keep the pipeline full, then take the oldest answer. False on a connection or server error. */
	private function receive(): bool {
		if ($this->failed) {
			return false;
		}
		while (count($this->inFlight) < $this->depth) {
			$id = self::nextId();
			$length = self::chunk($this->sftp);
			$packet = pack('Na*N3', strlen($this->handle), $this->handle, intdiv($this->next, 4294967296), $this->next % 4294967296, $length);
			if (!$this->sftp->_send_sftp_packet(self::FXP_READ, $packet, $id)) {
				return $this->fail();
			}
			$this->inFlight[] = [$id, $this->next, $length];
			$this->next += $length;
		}
		[$id, $offset, $length] = array_shift($this->inFlight);
		$response = $this->sftp->_get_sftp_packet($id);
		if (!is_string($response)) {
			return $this->fail();
		}
		if ($this->sftp->packet_type === self::FXP_DATA) {
				$data = substr($response, 4);
			if ($data === '') {
				return $this->fail();
			}
			$this->buffer .= $data;
			$this->depth = min(self::DEPTH, $this->depth * 2);
			if ($this->shortAnswer !== null) {
				// More data after a short answer: that one was the server's cap, not the end.
				// Never below what every server must support: a one-off small answer is no cap.
				self::$chunks ??= new \WeakMap();
				self::$chunks[$this->sftp] = max(self::MIN_CHUNK, min(self::chunk($this->sftp), $this->shortAnswer));
				$this->shortAnswer = null;
			}
			if (strlen($data) < $length) {
				// Fewer bytes than asked: the end of the file, or a server that caps a READ. The
				// requests behind this one assumed a full answer, so drop them and ask again from here.
				$this->drain();
				$this->next = $offset + strlen($data);
				$this->shortAnswer = strlen($data);
			}
			return true;
		}
		$status = $this->sftp->packet_type === self::FXP_STATUS ? unpack('N', $response) : false;
		if (is_array($status) && ($status[1] ?? null) === self::FX_EOF) {
			$this->eof = true;
			$this->shortAnswer = null;
			$this->drain();
			return true;
		}
		return $this->fail();
	}

	private function fail(): bool {
		$this->drain();
		$this->failed = true;
		return false;
	}

	/** Read and drop the answers still on their way, so the connection is free again. */
	private function drain(): void {
		foreach ($this->inFlight as [$id]) {
			if (!$this->failed) {
				$this->sftp->_get_sftp_packet($id);
			}
		}
		$this->inFlight = [];
	}

	private static function chunk(object $sftp): int {
		$learned = self::$chunks?->offsetExists($sftp) === true ? self::$chunks->offsetGet($sftp) : null;
		return is_int($learned) ? $learned : self::CHUNK;
	}

	private static function nextId(): int {
		self::$requestId = self::$requestId >= 0x7fffffff ? 0x4e470000 : self::$requestId + 1;
		return self::$requestId;
	}
}
