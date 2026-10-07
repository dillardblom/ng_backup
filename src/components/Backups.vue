<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Backups')"
		:description="t('ng_backup', 'Backups run in the background through Nextcloud\'s cron. Unchanged files are not read again; only new data is uploaded.')">
		<div class="ngb-row">
			<NcTextField v-model="schedule" class="ngb-time" :label="t('ng_backup', 'Daily at (HH:MM, empty = off)')" />
			<NcButton :disabled="schedule === status.schedule" @click="saveSchedule">{{ t('ng_backup', 'Save') }}</NcButton>
		</div>
		<div class="ngb-row">
			<NcButton v-for="target in status.targets" :key="target.id" :disabled="isRunning(target.id)" @click="start(target)">
				{{ isRunning(target.id) ? t('ng_backup', 'Backup to {name} running …', { name: target.name }) : t('ng_backup', 'Back up now to {name}', { name: target.name }) }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<template v-for="target in status.targets" :key="'trash' + target.id">
			<div v-if="trash[target.id]?.length" class="ngb-trash">
				<h3>{{ t('ng_backup', 'Trash of {name}', { name: target.name }) }}</h3>
				<p class="ngb-muted">{{ t('ng_backup', 'Removed restore points can be brought back until they are deleted permanently.') }}</p>
				<ul>
					<li v-for="item in trash[target.id]" :key="item.id">
						{{ item.time ? new Date(item.time).toLocaleString() : item.id }}{{ item.label ? ' – ' + item.label : '' }}
						<span class="ngb-muted">{{ t('ng_backup', 'removed by {by}, deleted after {date}', { by: item.by, date: new Date(item.deleteAfter * 1000).toLocaleDateString() }) }}</span>
						<NcButton variant="tertiary" @click="untrash(target, item)">{{ t('ng_backup', 'Restore') }}</NcButton>
					</li>
				</ul>
			</div>
		</template>

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
						<th>{{ t('ng_backup', 'Uploaded') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="run in status.runs" :key="run.id" :title="run.error || ''">
						<td>{{ new Date(run.startedAt * 1000).toLocaleString() }}</td>
						<td>{{ targetName(run.targetId) }}</td>
						<td>{{ run.kind }}</td>
						<td>{{ run.snapshot ? new Date(run.snapshot.createdAt * 1000).toLocaleString() + (run.snapshot.label ? ' – ' + run.snapshot.label : '') : '' }}</td>
						<td :class="'ngb-' + run.status">{{ run.status }}{{ run.status === 'running' ? ' (' + run.phase + ')' : '' }}{{ run.error ? ': ' + run.error : '' }}</td>
						<td>{{ run.stats?.files?.files ?? run.stats?.restored ?? '' }}</td>
						<td>{{ run.stats?.files ? formatSize(run.stats.files.uploaded) : '' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</NcSettingsSection>
</template>

<script setup>
import { ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../api.js'
import { formatSize } from '../format.js'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { reactive } from 'vue'

const props = defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])
const schedule = ref(props.status.schedule)
const error = ref('')
watch(() => props.status.schedule, (s) => { schedule.value = s })

const trash = reactive({})
async function loadTrash() {
	for (const target of props.status.targets) {
		try {
			trash[target.id] = await api.trash(target.id)
		} catch (e) {
			trash[target.id] = []
		}
	}
}
watch(() => props.status.targets.map(tg => tg.id).join(','), loadTrash, { immediate: true })

async function untrash(target, item) {
	error.value = ''
	try {
		await confirmPassword()
		await api.untrash(target.id, item.id)
		await loadTrash()
		emit('changed')
	} catch (e) {
		error.value = e?.message || ''
	}
}

const isRunning = (targetId) => props.status.runs.some(r => r.targetId === targetId && r.kind === 'full' && r.status === 'running')
const targetName = (id) => props.status.targets.find(tg => tg.id === id)?.name ?? '#' + id

async function start(target) {
	error.value = ''
	try {
		await api.startBackup(target.id, '')
		emit('changed')
	} catch (e) {
		error.value = e.message
	}
}

async function saveSchedule() {
	error.value = ''
	try {
		await api.setSchedule(schedule.value.trim())
		emit('changed')
	} catch (e) {
		error.value = e.message
	}
}
</script>

<style scoped>
.ngb-row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.ngb-time { max-width: 260px; }
.ngb-table-wrap { overflow-x: auto; }
.ngb-table { width: 100%; border-collapse: collapse; }
.ngb-table th, .ngb-table td { text-align: start; padding: 4px 8px; border-bottom: 1px solid var(--color-border); }
.ngb-failed { color: var(--color-error-text); }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-trash { margin: 12px 0; }
.ngb-trash h3 { font-weight: bold; }
.ngb-done { color: var(--color-success-text); }
</style>
