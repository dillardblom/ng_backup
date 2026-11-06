<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Repository;

/**
 * A restore cannot write to where it restores to (a full disk, permissions, something in the way).
 * The backup is fine, so this never counts as damaged data: the restore stops, also with --salvage.
 */
final class RestoreTargetException extends \RuntimeException {
}
