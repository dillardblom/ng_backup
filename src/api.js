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
	status: (allRuns = false) => call('get', '/status', allRuns ? { allRuns: true } : undefined),
	initKey: (passphrase, deleteDelayDays, label) => call('post', '/key', { passphrase, deleteDelayDays, label }),
	addSlot: (passphrase, label) => call('post', '/key/slots', { passphrase, label }),
	replaceSlot: (slot, passphrase, label) => call('put', `/key/slots/${slot}`, { passphrase, label }),
	removeSlot: (slot) => call('delete', `/key/slots/${slot}`),
	trash: (targetId, after = '') => call('get', `/targets/${targetId}/trash`, { after }),
	untrash: (targetId, snapshotId) => call('post', `/targets/${targetId}/trash/${snapshotId}`),
	kitUrl: () => url('/key/kit'),
	confirmKit: (accepted, phrase, code) => call('post', '/key/confirm', { accepted, phrase, code }),
	backends: () => call('get', '/backends'),
	addTarget: (target) => call('post', '/targets', target),
	targetSettings: (id) => call('get', `/targets/${id}/settings`),
	updateTarget: (id, target) => call('put', `/targets/${id}`, target),
	removeTarget: (id) => call('delete', `/targets/${id}`),
	testTarget: (id) => call('post', `/targets/${id}/test`),
	setTargetLimit: (id, maxGb) => call('put', `/targets/${id}/limit`, { maxGb }),
	startBackup: (targetId, label) => call('post', '/backups', { targetId, label }),
	tick: () => call('post', '/tick'),
	setPauseHeavyJobs: (enabled) => call('put', '/settings/pause-heavy-jobs', { enabled }),
	setTargetSchedule: (id, schedule, timezone) => call('put', `/targets/${id}/schedule`, { schedule, timezone }),
	snapshots: (targetId) => call('get', `/targets/${targetId}/snapshots`),
	setSnapshotNote: (targetId, snapshotId, note) => call('put', `/targets/${targetId}/snapshots/${snapshotId}/note`, { note }),
	setSnapshotPinned: (targetId, snapshotId, pinned) => call('put', `/targets/${targetId}/snapshots/${snapshotId}/pin`, { pinned }),
	browse: (targetId, snapshotId, path) => call('get', `/targets/${targetId}/snapshots/${snapshotId}/browse`, { path }),
	startRestore: (targetId, snapshotId, path, mode) => call('post', '/restores', { targetId, snapshotId, path, mode }),
}
