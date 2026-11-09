<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ngb-row">
		<strong class="ngb-name">{{ target.name }}</strong>
		<NcSelect v-model="kind" :options="kinds" label="label" :input-label="t('ng_backup', 'Schedule')" :clearable="false" />
		<NcTextField v-if="kind.id !== 'off'" v-model="time" class="ngb-time" :label="kind.id === 'daily' ? t('ng_backup', 'At (HH:MM, {zone})', { zone }) : t('ng_backup', 'From (HH:MM, {zone})', { zone })" />
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

const props = defineProps({ target: { type: Object, required: true } })
const emit = defineEmits(['changed', 'error'])

const kinds = [
	{ id: 'off', label: t('ng_backup', 'Off') },
	{ id: 'daily', label: t('ng_backup', 'Once a day') },
	{ id: 'hours', label: t('ng_backup', 'Every few hours') },
]
// A schedule is shown and edited in the zone it was saved in, named next to the time: "every 6
// hours from 02:00" runs until midnight of that zone, so moving it to another zone would change
// how often it runs. A location without a schedule gets the administrator's own zone (the browser's).
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
const zone = computed(() => props.target.schedule !== '' && props.target.scheduleZone ? props.target.scheduleZone : browserZone)
const kind = ref(kinds[0])
const time = ref('02:00')
const hours = ref('6')
const busy = ref(false)

const current = computed(() => {
	const [at, every] = props.target.schedule.split('/')
	return at === '' ? '' : at + (every ? '/' + every : '')
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
		await api.setTargetSchedule(props.target.id, wanted.value, zone.value)
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
