<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

use OCA\NgBackup\Backend\IBackend;

/**
 * Reads blobs from packs. Restores and deep verifies read the blobs of a snapshot in the order
 * they were written, so the stream of the pack read last is kept open and read on from where it
 * is: one request per pack instead of one per blob (a round trip each, and on most remote
 * storages a new connection or file handle too). Anything else opens the pack at the offset,
 * as a single read always did.
 *
 * The open stream must not overlap other requests on the location (SFTP cannot read and write
 * on one connection at once): Repository reaches the location through a PackReaderGuard, which
 * closes it first.
 */
final class PackReader {
	/** A gap up to this size in the open stream is read past instead of opening the pack again. */
	private const SKIP_MAX = 8 * 1048576;
	private const CHUNK = 1048576;

	/** @var resource|null */
	private $stream = null;
	private string $pack = '';
	private int $pos = 0;

	public function __construct(
		private IBackend $backend,
	) {
	}

	public function read(string $pack, int $offset, int $length): string {
		if ($this->stream !== null && $this->pack === $pack && $offset >= $this->pos && $offset - $this->pos <= self::SKIP_MAX) {
			try {
				$this->skipTo($offset);
				return $this->readExactly($length);
			} catch (\Exception) {
				// The open stream broke (e.g. a timeout); open the pack again below.
			}
		}
		$this->close();
		$fh = $this->backend->get('packs/' . substr($pack, 0, 2) . '/' . $pack);
		$this->stream = $fh;
		$this->pack = $pack;
		$this->pos = 0;
		try {
			if ($offset > 0 && (stream_get_meta_data($fh)['seekable'] ?? false) && fseek($fh, $offset) === 0) {
				$this->pos = $offset;
			}
			$this->skipTo($offset); // a stream that cannot seek is read up to the offset
			return $this->readExactly($length);
		} catch (\Exception $e) {
			$this->close();
			throw $e;
		}
	}

	public function close(): void {
		$fh = $this->stream;
		$this->stream = null;
		if ($fh !== null) {
			@fclose($fh);
		}
	}

	public function __destruct() {
		$this->close();
	}

	private function skipTo(int $offset): void {
		while ($this->pos < $offset) {
			$this->readExactly(min($offset - $this->pos, self::CHUNK));
		}
	}

	/** Read $length bytes from the open stream; a short read closes it. */
	private function readExactly(int $length): string {
		$fh = $this->stream ?? throw new RepositoryException('No open pack stream');
		$data = '';
		while (strlen($data) < $length) {
			$chunk = fread($fh, min($length - strlen($data), self::CHUNK));
			if ($chunk === false || $chunk === '') {
				$this->close();
				throw new RepositoryException('Short read in pack ' . $this->pack);
			}
			$data .= $chunk;
		}
		$this->pos += $length;
		return $data;
	}
}
