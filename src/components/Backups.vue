<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Backups')"
		:description="t('ng_backup', 'Backups run in the background through Nextcloud\'s cron. Unchanged files are not read again; only new data is uploaded.')">
		<LocationScheduleRow v-for="target in status.targets" :key="target.id" :target="target" @changed="emit('changed')" @error="error = $event" />
		<NcCheckboxRadioSwitch :model-value="status.heavyJobs.enabled" @update:model-value="savePause">
			{{ t('ng_backup', 'Pause heavy background jobs (Memories, Recognize, Preview Generator, full text search, antivirus scan) while a backup runs, at most 6 hours in a row') }}
		</NcCheckboxRadioSwitch>
		<p v-if="status.heavyJobs.jobs.length" class="ngb-muted">{{ t('ng_backup', 'Paused now: {jobs}', { jobs: status.heavyJobs.jobs.join(', ') }) }}</p>
		<div class="ngb-row">
			<NcTextField v-model="label" class="ngb-label" :maxlength="100" :label="t('ng_backup', 'Label (optional)')"
				:placeholder="t('ng_backup', 'e.g. before the upgrade to Nextcloud 35')" />
			<NcButton v-for="target in status.targets" :key="target.id" :disabled="isRunning(target.id)" @click="start(target)">
				{{ isRunning(target.id) ? t('ng_backup', 'Backup to {name} running …', { name: target.name }) : t('ng_backup', 'Back up now to {name}', { name: target.name }) }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<div v-if="status.runs.length" class="ngb-table-wrap">
			<table class="ngb-table">
				<thead>
					<tr>
						<th>{{ t('ng_backup', 'Started') }}</th>
						<th>{{ t('ng_backup', 'Location') }}</th>
						<th>{{ t('ng_backup', 'Kind') }}</th>
						<th>{{ t('ng_backup', 'Restore point') }}</th>
						<th>{{ t('ng_backup', 'Status') }}</th>
						<th>{{ t('ng_backup', 'Files') }}</th>
						<th :title="t('ng_backup', 'Total size of the files in this backup, unchanged files included')">{{ t('ng_backup', 'Size') }}</th>
						<th>{{ t('ng_backup', 'Uploaded') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="run in status.runs" :key="run.id" :title="run.error || ''">
						<td>{{ formatDateTime(run.startedAt) }}</td>
						<td>{{ targetName(run.targetId) }}</td>
						<td>{{ run.kind }}</td>
						<td>{{ run.snapshot ? (run.snapshot.pinned ? '📌 ' : '') + formatDateTime(run.snapshot.createdAt) + (run.snapshot.label ? ' – ' + run.snapshot.label : '') + (run.snapshot.note ? ' – ' + run.snapshot.note : '') : '' }}</td>
						<td :class="'ngb-' + run.status">{{ runStatus(run) }}{{ run.error ? ': ' + run.error : '' }}</td>
						<td>{{ run.stats?.files?.files ?? run.stats?.restored ?? '' }}</td>
						<td>{{ run.stats?.files ? formatSize(run.stats.files.bytes) : '' }}</td>
						<td>{{ run.stats?.files ? formatSize(run.stats.files.uploaded) : '' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-if="allRuns || status.olderRuns" class="ngb-muted">
			<template v-if="allRuns">
				{{ t('ng_backup', 'All runs.') }}
				<NcButton variant="tertiary" @click="emit('show-all-runs', false)">{{ n('ng_backup', 'Only the last day', 'Only the last %n days', status.recentDays) }}</NcButton>
			</template>
			<template v-else>
				{{ n('ng_backup', 'Runs of the last day.', 'Runs of the last %n days.', status.recentDays) }}
				<NcButton variant="tertiary" @click="emit('show-all-runs', true)">{{ n('ng_backup', 'Show %n older run', 'Show %n older runs', status.olderRuns) }}</NcButton>
			</template>
		</p>
	</NcSettingsSection>
</template>

<script setup>
import { ref } from 'vue'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import LocationScheduleRow from './LocationScheduleRow.vue'
import api from '../api.js'
import { formatDateTime, formatSize } from '../format.js'

const props = defineProps({ status: { type: Object, required: true }, allRuns: { type: Boolean, default: false } })
const emit = defineEmits(['changed', 'show-all-runs'])
const error = ref('')

const label = ref('')

// ": 47/183 tables" in the database phase; " 68%", or " ~68%" when the total is estimated from
// Nextcloud's file cache (first backup)
const percent = (run) => {
	if (!run.progress) {
		return ''
	}
	if (run.progress.totalTables) {
		return ': ' + t('ng_backup', '{done}/{total} tables', { done: run.progress.tables, total: run.progress.totalTables })
	}
	return ' ' + (run.progress.approximate ? '~' : '') + Math.floor(run.progress.fraction * 100) + '%'
}
// "running (db: 47/183 tables)", or "waiting for background jobs (…, last step 12 min ago)"
const runStatus = (run) => {
	if (run.status !== 'running') {
		return run.status
	}
	if (run.queued) {
		return t('ng_backup', 'queued, after the backup that is running now')
	}
	const detail = run.phase + percent(run)
	if (run.waitingFor) {
		return t('ng_backup', 'waiting for background jobs ({detail}, last step {minutes} min ago)', { detail, minutes: Math.floor(run.waitingFor / 60) })
	}
	return run.status + ' (' + detail + ')'
}
const isRunning = (targetId) => props.status.runs.some(r => r.targetId === targetId && r.kind === 'full' && r.status === 'running')
const targetName = (id) => props.status.targets.find(tg => tg.id === id)?.name ?? '#' + id

async function savePause(enabled) {
	error.value = ''
	try {
		await api.setPauseHeavyJobs(enabled)
		emit('changed')
	} catch (e) {
		error.value = e.message
	}
}

async function start(target) {
	error.value = ''
	try {
		await api.startBackup(target.id, label.value.trim())
		label.value = ''
		emit('changed')
	} catch (e) {
		error.value = e.message
	}
}
</script>

<style scoped>
.ngb-row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.ngb-label { max-width: 360px; }
.ngb-table-wrap { overflow-x: auto; }
.ngb-table { width: 100%; border-collapse: collapse; }
.ngb-table th, .ngb-table td { text-align: start; padding: 4px 8px; border-bottom: 1px solid var(--color-border); }
.ngb-failed { color: var(--color-error-text); }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-done { color: var(--color-success-text); }
</style>
