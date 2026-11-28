<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Backend;

/** The SFTP server's host key is not one of the location's pinned keys (#34): nothing was sent to it. */
final class HostKeyMismatchException extends BackendException {
	/** @param list<string> $expected */
	public function __construct(
		public readonly string $endpoint,
		public readonly array $expected,
		public readonly string $received,
		?\Throwable $previous = null,
	) {
		parent::__construct("The host key of $endpoint does not match the pinned key: expected " . implode(' or ', $expected)
			. ", received $received. Nothing was sent to this server. If the server's key was changed on purpose, accept the new key"
			. ' (occ backup:target:host-key <location> --pin); otherwise the connection may be intercepted', 0, $previous);
	}
}
