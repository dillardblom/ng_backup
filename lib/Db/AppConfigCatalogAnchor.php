<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\NgBackup\Db;

use OCA\NgBackup\AppInfo\Application;
use OCA\NgBackup\Repository\ICatalogAnchor;
use OCP\IAppConfig;

/** Keeps the newest catalog generation seen per repository in the app config (outside the location). */
final class AppConfigCatalogAnchor implements ICatalogAnchor {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function get(string $repositoryId): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $this->key($repositoryId), '', true);
		$data = $raw === '' ? null : json_decode($raw, true);
		return is_array($data) && isset($data['gen'], $data['hash']) ? ['gen' => (int)$data['gen'], 'hash' => (string)$data['hash']] : null;
	}

	public function set(string $repositoryId, int $gen, string $hash): void {
		$this->appConfig->setValueString(Application::APP_ID, $this->key($repositoryId), json_encode(['gen' => $gen, 'hash' => $hash]), true);
	}

	private function key(string $repositoryId): string {
		return 'catalog_' . preg_replace('/[^a-f0-9]/', '', $repositoryId);
	}
}
