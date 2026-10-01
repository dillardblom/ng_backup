<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Backup locations')"
		:description="t('ng_backup', 'Uses the connection types of External storage (S3, SFTP, WebDAV, ...), but never mounts them: the location is only connected while a backup or restore runs. Credentials are stored encrypted.')">
		<ul v-if="status.targets.length" class="ngb-list">
			<li v-for="target in status.targets" :key="target.id">
				<strong>{{ target.name }}</strong>
				<span class="ngb-muted">{{ target.backend }} · {{ target.path }}</span>
				<span v-if="tests[target.id]" :class="tests[target.id].ok ? 'ngb-ok' : 'ngb-err'">
					{{ tests[target.id].ok ? t('ng_backup', 'OK, {n} snapshots', { n: tests[target.id].snapshots }) : tests[target.id].error }}
				</span>
				<NcButton variant="tertiary" @click="test(target)">{{ t('ng_backup', 'Test') }}</NcButton>
				<NcButton variant="tertiary" @click="remove(target)">{{ t('ng_backup', 'Remove') }}</NcButton>
			</li>
		</ul>

		<NcButton v-if="!adding" @click="startAdd">{{ t('ng_backup', 'Add location') }}</NcButton>
		<form v-else class="ngb-form" @submit.prevent="add">
			<NcTextField v-model="form.name" :label="t('ng_backup', 'Name')" />
			<NcSelect v-model="backend" :options="backends" label="name" :input-label="t('ng_backup', 'Type')" :clearable="false" />
			<NcSelect v-if="backend" v-model="auth" :options="backend.auth" label="name" :input-label="t('ng_backup', 'Authentication')" :clearable="false" />
			<template v-for="field in fields" :key="field.key">
				<NcCheckboxRadioSwitch v-if="field.type === 1" v-model="form.options[field.key]">{{ field.label }}</NcCheckboxRadioSwitch>
				<NcPasswordField v-else-if="field.type === 2" v-model="form.options[field.key]" :label="field.label + (field.optional ? '' : ' *')" autocomplete="off" />
				<NcTextField v-else v-model="form.options[field.key]" :label="field.label + (field.optional ? '' : ' *')" />
			</template>
			<NcTextField v-model="form.path" :label="t('ng_backup', 'Folder on the location for the backups')" />
			<div class="ngb-row">
				<NcButton type="submit" variant="primary" :disabled="busy || !form.name || !backend || !auth">{{ t('ng_backup', 'Connect and add') }}</NcButton>
				<NcButton @click="adding = false">{{ t('ng_backup', 'Cancel') }}</NcButton>
			</div>
		</form>
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
	</NcSettingsSection>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../api.js'

defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const adding = ref(false)
const busy = ref(false)
const error = ref('')
const backends = ref([])
const backend = ref(null)
const auth = ref(null)
const tests = reactive({})
const form = reactive({ name: '', path: 'ng_backup', options: {} })

// files_external DefinitionParameter: type 0 text, 1 boolean, 2 password; flag 1 = optional, 4 = hidden
const toFields = (params) => Object.entries(params || {})
	.filter(([, p]) => !(p.flags & 4))
	.map(([key, p]) => ({ key, label: p.value, type: p.type, optional: !!(p.flags & 1) }))
const fields = computed(() => [...toFields(backend.value?.parameters), ...toFields(auth.value?.parameters)])

watch(backend, (b) => {
	auth.value = b?.auth?.[0] ?? null
	form.options = {}
})

async function startAdd() {
	adding.value = true
	error.value = ''
	if (!backends.value.length) {
		try {
			backends.value = await api.backends()
		} catch (e) {
			error.value = e.message
		}
	}
}

async function add() {
	busy.value = true
	error.value = ''
	try {
		await api.addTarget({ name: form.name, backend: backend.value.id, auth: auth.value.id, options: form.options, path: form.path })
		adding.value = false
		Object.assign(form, { name: '', path: 'ng_backup', options: {} })
		emit('changed')
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}

async function test(target) {
	tests[target.id] = await api.testTarget(target.id)
}

async function remove(target) {
	if (!window.confirm(t('ng_backup', 'Remove "{name}" from NG Backup? The backups on the location itself are kept.', { name: target.name }))) {
		return
	}
	await api.removeTarget(target.id)
	emit('changed')
}
</script>

<style scoped>
.ngb-list li { display: flex; align-items: center; gap: 12px; padding: 4px 0; }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-ok { color: var(--color-success-text); }
.ngb-err { color: var(--color-error-text); }
.ngb-form { display: flex; flex-direction: column; gap: 10px; max-width: 600px; margin-top: 12px; }
.ngb-row { display: flex; gap: 8px; }
</style>
