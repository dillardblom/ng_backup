<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript('ng_backup', 'ng_backup-admin');
		Util::addStyle('ng_backup', 'ng_backup-admin');
		return new TemplateResponse('ng_backup', 'admin');
	}

	public function getSection(): string {
		return 'ng_backup';
	}

	public function getPriority(): int {
		return 10;
	}
}
