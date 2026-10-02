// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/ng_backup/api' + path)

/** Unwraps {error} responses into thrown Errors with the server's message. */
async function call(method, path, data) {
	try {
		const response = await axios({ method, url: url(path), data, params: method === 'get' ? data : undefined })
		return response.data
	} catch (e) {
		throw new Error(e.response?.data?.error || e.message)
	}
}

export default {
	status: () => call('get', '/status'),
	initKey: (passphrase, deleteDelayDays, label) => call('post', '/key', { passphrase, deleteDelayDays, label }),
	addSlot: (passphrase, label) => call('post', '/key/slots', { passphrase, label }),
	replaceSlot: (slot, passphrase, label) => call('put', `/key/slots/${slot}`, { passphrase, label }),
	removeSlot: (slot) => call('delete', `/key/slots/${slot}`),
	trash: (targetId) => call('get', `/targets/${targetId}/trash`),
	untrash: (targetId, snapshotId) => call('post', `/targets/${targetId}/trash/${snapshotId}`),
	kitUrl: () => url('/key/kit'),
	confirmKit: (accepted, phrase, code) => call('post', '/key/confirm', { accepted, phrase, code }),
	backends: () => call('get', '/backends'),
	addTarget: (target) => call('post', '/targets', target),
	removeTarget: (id) => call('delete', `/targets/${id}`),
	testTarget: (id) => call('post', `/targets/${id}/test`),
	startBackup: (targetId, label) => call('post', '/backups', { targetId, label }),
	setSchedule: (time) => call('put', '/schedule', { time }),
	snapshots: (targetId) => call('get', `/targets/${targetId}/snapshots`),
	browse: (targetId, snapshotId, path) => call('get', `/targets/${targetId}/snapshots/${snapshotId}/browse`, { path }),
	startRestore: (targetId, snapshotId, path, mode) => call('post', '/restores', { targetId, snapshotId, path, mode }),
}
