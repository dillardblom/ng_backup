<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Service;

/** A step after the database was committed failed: the database is no longer the fresh one. */
final class DatabaseAlreadyRestoredException extends \RuntimeException {
}
