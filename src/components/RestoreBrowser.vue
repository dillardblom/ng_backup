<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Restore')"
		:description="t('ng_backup', 'Pick a restore point and a user folder or file. Restores run in the background and go through Nextcloud, so overwritten files keep their old content as a version and removed files go to the trash.')">
		<div class="ngb-row">
			<NcSelect v-model="target" :options="status.targets" label="name" :input-label="t('ng_backup', 'Location')" :clearable="false" />
			<NcSelect v-if="target" v-model="snapshot" :options="snapshots" :get-option-label="snapshotLabel" :input-label="t('ng_backup', 'Restore point')" :clearable="false" />
		</div>

		<template v-if="snapshot">
			<div class="ngb-crumbs">
				<NcButton variant="tertiary" @click="open('data')">data</NcButton>
				<template v-for="(crumb, i) in crumbs" :key="crumb.path">
					<span>/</span>
					<NcButton variant="tertiary" :disabled="i === crumbs.length - 1" @click="open(crumb.path)">{{ crumb.name }}</NcButton>
				</template>
			</div>
			<NcLoadingIcon v-if="loading" />
			<ul v-else class="ngb-entries">
				<li v-for="item in items" :key="item.name" :class="{ selected: selected === join(item.name) }">
					<NcButton v-if="item.type === 'dir'" variant="tertiary" @click="open(join(item.name))">📁 {{ item.name }}/</NcButton>
					<span v-else class="ngb-file">📄 {{ item.name }}</span>
					<span class="ngb-muted">{{ formatSize(item.size) }}{{ item.type === 'dir' ? ' · ' + item.files + ' files' : '' }}</span>
					<NcButton v-if="restorable(join(item.name))" variant="tertiary" @click="selected = join(item.name)">{{ t('ng_backup', 'Select') }}</NcButton>
				</li>
			</ul>
			<NcButton v-if="restorable(path) && path !== selected" variant="tertiary" @click="selected = path">{{ t('ng_backup', 'Select this folder') }}</NcButton>

			<div v-if="selected" class="ngb-restore">
				<p>{{ t('ng_backup', 'Selected: {path}, from the restore point of {date}', { path: selected, date: snapshotLabel(snapshot) }) }}</p>
				<NcCheckboxRadioSwitch v-for="m in modes" :key="m.id" v-model="mode" :value="m.id" type="radio" name="ngb-mode">{{ m.label }}</NcCheckboxRadioSwitch>
				<NcButton variant="primary" :disabled="busy" @click="restore">{{ t('ng_backup', 'Restore') }}</NcButton>
			</div>
		</template>
		<NcNoteCard v-if="message" type="success">{{ message }}</NcNoteCard>
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
	</NcSettingsSection>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import api from '../api.js'
import { formatSize } from '../format.js'

const props = defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const target = ref(props.status.targets[0] ?? null)
const snapshots = ref([])
const snapshot = ref(null)
const path = ref('data')
const items = ref([])
const loading = ref(false)
const selected = ref('')
const mode = ref('new-folder')
const busy = ref(false)
const error = ref('')
const message = ref('')
const modes = [
	{ id: 'new-folder', label: t('ng_backup', 'Into a new folder "Restored <date>" (nothing existing is changed)') },
	{ id: 'merge', label: t('ng_backup', 'Into the original place, overwrite (old content kept as a version)') },
	{ id: 'replace', label: t('ng_backup', 'Into the original place, overwrite and move files that are not in the backup to the trash') },
]

const snapshotLabel = (s) => new Date(s.createdAt * 1000).toLocaleString() + (s.label ? ' – ' + s.label : '')
const join = (name) => path.value + '/' + name
const restorable = (p) => /^data\/[^/]+\/files(\/.*)?$/.test(p)
const crumbs = computed(() => {
	const parts = path.value.split('/').slice(1)
	return parts.map((name, i) => ({ name, path: ['data', ...parts.slice(0, i + 1)].join('/') }))
})

watch(target, async (tg) => {
	snapshot.value = null
	snapshots.value = tg ? await api.snapshots(tg.id) : []
	snapshot.value = snapshots.value[0] ?? null
}, { immediate: true })
watch(snapshot, () => { selected.value = ''; open('data') })

async function open(p) {
	if (!snapshot.value) {
		return
	}
	path.value = p
	loading.value = true
	error.value = ''
	try {
		items.value = await api.browse(target.value.id, snapshot.value.id, p)
	} catch (e) {
		error.value = e.message
	} finally {
		loading.value = false
	}
}

async function restore() {
	busy.value = true
	error.value = message.value = ''
	try {
		await confirmPassword()
		await api.startRestore(target.value.id, snapshot.value.id, selected.value, mode.value)
		message.value = t('ng_backup', 'Restore queued. It runs with the next cron job; progress is shown under Backups.')
		emit('changed')
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.ngb-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.ngb-crumbs { display: flex; align-items: center; flex-wrap: wrap; }
.ngb-entries li { display: flex; align-items: center; gap: 12px; min-height: 36px; }
.ngb-entries li.selected { background: var(--color-primary-element-light); border-radius: var(--border-radius); }
.ngb-file { padding-inline-start: 12px; }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-restore { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; max-width: 700px; }
</style>
