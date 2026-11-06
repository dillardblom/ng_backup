<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ngb-row">
		<strong class="ngb-name">{{ target.name }}</strong>
		<NcSelect v-model="kind" :options="kinds" label="label" :input-label="t('ng_backup', 'Schedule')" :clearable="false" />
		<NcTextField v-if="kind.id !== 'off'" v-model="time" class="ngb-time" :label="kind.id === 'daily' ? t('ng_backup', 'At (HH:MM, {zone})', { zone: browserZone }) : t('ng_backup', 'From (HH:MM, {zone})', { zone: browserZone })" />
		<NcTextField v-if="kind.id === 'hours'" v-model="hours" class="ngb-hours" type="number" min="1" max="23" :label="t('ng_backup', 'Every … hours')" />
		<NcButton :disabled="busy || wanted === current" @click="save">{{ t('ng_backup', 'Save') }}</NcButton>
	</div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../api.js'
import { toZone } from '../format.js'

const props = defineProps({ target: { type: Object, required: true } })
const emit = defineEmits(['changed', 'error'])

const kinds = [
	{ id: 'off', label: t('ng_backup', 'Off') },
	{ id: 'daily', label: t('ng_backup', 'Once a day') },
	{ id: 'hours', label: t('ng_backup', 'Every few hours') },
]
// The time is saved in the administrator's own zone (the browser's); a schedule saved in another
// zone is shown in this one, so it reads as the moment it runs here.
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
const kind = ref(kinds[0])
const time = ref('02:00')
const hours = ref('6')
const busy = ref(false)

const current = computed(() => {
	const [at, every] = props.target.schedule.split('/')
	return at === '' ? '' : (props.target.scheduleZone !== browserZone ? toZone(at, props.target.scheduleZone, browserZone) : at) + (every ? '/' + every : '')
})
const wanted = computed(() => {
	if (kind.value.id === 'off') {
		return ''
	}
	return time.value.trim() + (kind.value.id === 'hours' ? '/' + String(hours.value).trim() : '')
})

watch(current, (schedule) => {
	const [at, every] = schedule.split('/')
	kind.value = kinds[at === '' ? 0 : (every ? 2 : 1)]
	time.value = at === '' ? time.value : at
	hours.value = every ?? hours.value
}, { immediate: true })

async function save() {
	busy.value = true
	emit('error', '')
	try {
		await api.setTargetSchedule(props.target.id, wanted.value, browserZone)
		emit('changed')
	} catch (e) {
		emit('error', e.message)
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.ngb-row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.ngb-name { min-width: 120px; align-self: center; }
.ngb-time { max-width: 220px; }
.ngb-hours { max-width: 120px; }
</style>
