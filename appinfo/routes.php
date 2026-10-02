<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'AdminApi#status', 'url' => '/api/status', 'verb' => 'GET'],
		['name' => 'AdminApi#initKey', 'url' => '/api/key', 'verb' => 'POST'],
		['name' => 'AdminApi#downloadKit', 'url' => '/api/key/kit', 'verb' => 'GET'],
		['name' => 'AdminApi#confirmKit', 'url' => '/api/key/confirm', 'verb' => 'POST'],
		['name' => 'AdminApi#addSlot', 'url' => '/api/key/slots', 'verb' => 'POST'],
		['name' => 'AdminApi#replaceSlot', 'url' => '/api/key/slots/{slot}', 'verb' => 'PUT'],
		['name' => 'AdminApi#removeSlot', 'url' => '/api/key/slots/{slot}', 'verb' => 'DELETE'],
		['name' => 'AdminApi#trash', 'url' => '/api/targets/{targetId}/trash', 'verb' => 'GET'],
		['name' => 'AdminApi#untrash', 'url' => '/api/targets/{targetId}/trash/{snapshotId}', 'verb' => 'POST'],
		['name' => 'AdminApi#backends', 'url' => '/api/backends', 'verb' => 'GET'],
		['name' => 'AdminApi#addTarget', 'url' => '/api/targets', 'verb' => 'POST'],
		['name' => 'AdminApi#removeTarget', 'url' => '/api/targets/{id}', 'verb' => 'DELETE'],
		['name' => 'AdminApi#testTarget', 'url' => '/api/targets/{id}/test', 'verb' => 'POST'],
		['name' => 'AdminApi#startBackup', 'url' => '/api/backups', 'verb' => 'POST'],
		['name' => 'AdminApi#setSchedule', 'url' => '/api/schedule', 'verb' => 'PUT'],
		['name' => 'AdminApi#snapshots', 'url' => '/api/targets/{targetId}/snapshots', 'verb' => 'GET'],
		['name' => 'AdminApi#browse', 'url' => '/api/targets/{targetId}/snapshots/{snapshotId}/browse', 'verb' => 'GET'],
		['name' => 'AdminApi#startRestore', 'url' => '/api/restores', 'verb' => 'POST'],
	],
];
