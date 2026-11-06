<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Bin')"
		:description="t('ng_backup', 'Restore points that were removed, most of them by the retention policy. They can be brought back until they are deleted permanently.')">
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<div v-if="reading" class="ngb-reading" role="status">
			<template v-if="reading.total === null">
				<NcLoadingIcon :size="20" />
				<span>{{ t('ng_backup', 'Connecting to {location} …', { location: reading.name }) }}</span>
			</template>
			<template v-else>
				<span>{{ t('ng_backup', 'Reading removed restore points from {location}: {done} of {total}', { location: reading.name, done: reading.done, total: reading.total }) }}</span>
				<NcProgressBar :value="reading.total ? Math.round(100 * reading.done / reading.total) : 0" />
			</template>
			<p class="ngb-muted">{{ t('ng_backup', 'This can take a while: every removed restore point is read from the location.') }}</p>
		</div>
		<NcNoteCard v-else-if="empty && !error" type="info">{{ t('ng_backup', 'The bin is empty.') }}</NcNoteCard>

		<template v-for="target in status.targets" :key="'trash' + target.id">
			<div v-if="trash[target.id]?.length" class="ngb-trash">
				<h3>{{ status.targets.length > 1 ? target.name : t('ng_backup', 'Removed restore points') }}</h3>
				<ul>
					<li v-for="item in trash[target.id]" :key="item.id">
						{{ item.time ? formatDateTime(item.time) : item.id }}{{ item.label ? ' – ' + item.label : '' }}
						<span class="ngb-muted">{{ t('ng_backup', 'removed by {by}, deleted after {date}', { by: item.by, date: formatDate(item.deleteAfter) }) }}</span>
						<button v-if="item.by === RETENTION"
							class="ngb-info"
							:aria-expanded="retentionInfo ? 'true' : 'false'"
							:aria-label="t('ng_backup', 'What does this mean?')"
							:title="t('ng_backup', 'What does this mean?')"
							@click="retentionInfo = !retentionInfo">
							ⓘ
						</button>
						<NcButton variant="tertiary" @click="untrash(target, item)">{{ t('ng_backup', 'Restore') }}</NcButton>
					</li>
				</ul>
				<NcNoteCard v-if="retentionInfo && trash[target.id].some(item => item.by === RETENTION)" type="info">
					{{ n('ng_backup',
						'The retention policy keeps the newest restore points, and for older days, weeks and months only the last restore point of each. It moves the others to this bin, where they stay for %n day before they are deleted permanently. Until then you can bring one back with "Restore". Nothing was lost by accident.',
						'The retention policy keeps the newest restore points, and for older days, weeks and months only the last restore point of each. It moves the others to this bin, where they stay for %n days before they are deleted permanently. Until then you can bring one back with "Restore". Nothing was lost by accident.',
						status.key.deleteDelayDays) }}
				</NcNoteCard>
			</div>
		</template>
	</NcSettingsSection>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { n, t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import api from '../api.js'
import { formatDate, formatDateTime } from '../format.js'

const props = defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

// "by" of a restore point the weekly cleanup removed (PruneService)
const RETENTION = 'retention policy'
const retentionInfo = ref(false)
const trash = reactive({})
const reading = ref({ name: '', total: null, done: 0 }) // the location being read; null when done
const error = ref('')
const empty = computed(() => props.status.targets.every(target => !trash[target.id]?.length))

let current = 0 // the newest load; an older one stops (another took over, or the tab was left)
onBeforeUnmount(() => { current++ })

async function loadTarget(target, load) {
	reading.value = { name: target.name, total: null, done: 0 }
	const items = []
	for (;;) {
		const page = await api.trash(target.id, items.length ? items[items.length - 1].id : '')
		if (load !== current) {
			return
		}
		items.push(...page.items)
		reading.value = { name: target.name, total: page.total, done: Math.min(items.length, page.total) }
		if (!page.items.length || items.length >= page.total) {
			break
		}
	}
	trash[target.id] = items.sort((a, b) => String(b.time ?? '').localeCompare(String(a.time ?? '')))
}

async function loadTrash() {
	const load = ++current
	const failed = []
	for (const target of props.status.targets) {
		try {
			await loadTarget(target, load)
		} catch (e) {
			// keep what was shown: a location that cannot be reached does not mean its bin is empty
			failed.push(target.name + (e?.message ? ': ' + e.message : ''))
		}
		if (load !== current) {
			return
		}
	}
	error.value = failed.length ? t('ng_backup', 'The bin could not be read from {locations}', { locations: failed.join('; ') }) : ''
	reading.value = null
}
// Again when a run ends: a cleanup moves restore points here.
watch(() => props.status.targets.map(tg => tg.id).join(',') + '|' + (props.status.runs ?? []).filter(run => run.status !== 'running').map(run => run.id).join(','), loadTrash, { immediate: true })

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
</script>

<style scoped>
.ngb-info { background: none; border: none; padding: 0 4px; min-height: auto; cursor: pointer; color: var(--color-primary-element); }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-reading { margin: 12px 0; display: flex; flex-direction: column; gap: 6px; max-width: 600px; }
.ngb-reading p { margin: 0; }
.ngb-trash { margin: 12px 0; }
.ngb-trash h3 { font-weight: bold; }
</style>
