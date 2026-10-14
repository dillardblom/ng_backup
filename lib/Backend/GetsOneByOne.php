<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/** IBackend::getMany() and getRanges() for a backend where reading one after the other costs nothing extra. */
trait GetsOneByOne {
	/**
	 * @param list<string> $paths
	 * @return \Generator<string, string>
	 */
	public function getMany(array $paths): \Generator {
		foreach ($paths as $path) {
			$fh = $this->get($path);
			try {
				$data = stream_get_contents($fh);
			} finally {
				fclose($fh);
			}
			if ($data === false) {
				throw new BackendException('Read failed for ' . $path);
			}
			yield $path => $data;
		}
	}

	/**
	 * @param list<array{0:string, 1:int, 2:int}> $ranges
	 * @return \Generator<int, string>
	 */
	public function getRanges(array $ranges): \Generator {
		foreach ($ranges as $i => [$path, $offset, $length]) {
			yield $i => $this->getRange($path, $offset, $length);
		}
	}
}
