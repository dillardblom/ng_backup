<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Command;

use OCA\NgBackup\Service\KeyService;
use OCA\NgBackup\Service\KeySlotService;

final class KeySlotAdd extends KeySlots {
	public function __construct(KeyService $keys, KeySlotService $slots) {
		parent::__construct($keys, $slots, 'add');
	}
}
