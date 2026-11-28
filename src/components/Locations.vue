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
				<span class="ngb-muted">{{ target.backend }} · {{ target.path }}{{ target.appendOnly ? ' · ' + t('ng_backup', 'append-only') : '' }}</span>
				<span :class="usageClass(target)">{{ usageText(target) }}</span>
				<span v-if="target.hostKeys === 0" class="ngb-muted" :title="t('ng_backup', 'Nextcloud does not check the SFTP server\'s host key for scheduled backups. Test the location to pin it.')">
					{{ t('ng_backup', 'host key not pinned') }}
				</span>
				<span v-if="tests[target.id]" :class="tests[target.id].ok ? 'ngb-ok' : 'ngb-err'">
					{{ tests[target.id].ok ? t('ng_backup', 'OK, {n} snapshots', { n: tests[target.id].snapshots }) : tests[target.id].error }}
				</span>
				<NcButton variant="tertiary" @click="test(target)">{{ t('ng_backup', 'Test') }}</NcButton>
				<NcButton variant="tertiary" @click="startEdit(target)">{{ t('ng_backup', 'Edit') }}</NcButton>
				<NcButton variant="tertiary" @click="remove(target)">{{ t('ng_backup', 'Remove') }}</NcButton>
				<div v-if="keys[target.id]" class="ngb-keys">
					<template v-if="keys[target.id].error">
						<span class="ngb-err">{{ t('ng_backup', 'The server\'s host keys could not be read: {error}', { error: keys[target.id].error }) }}</span>
					</template>
					<template v-else-if="!keys[target.id].pinned.length">
						<span>{{ t('ng_backup', 'Pin the host key? These are the keys the server offers now. Compare them with what your provider publishes; once pinned, NG Backup refuses a server with another key.') }}</span>
						<code v-for="key in keys[target.id].server" :key="key.fingerprint">{{ key.type }} {{ key.fingerprint }}</code>
						<div class="ngb-row">
							<NcButton variant="primary" :disabled="busy" @click="pinKeys(target, keys[target.id].server)">{{ t('ng_backup', 'Pin host keys') }}</NcButton>
							<NcButton :disabled="busy" @click="delete keys[target.id]">{{ t('ng_backup', 'Not now') }}</NcButton>
						</div>
					</template>
					<template v-else-if="!matches(keys[target.id])">
						<span class="ngb-err">{{ t('ng_backup', 'None of the server\'s host keys is pinned: backups to this location are refused. If your provider changed the keys, accept the new ones; otherwise the connection may be intercepted. All administrators are notified either way.') }}</span>
						<span>{{ t('ng_backup', 'Pinned:') }}</span>
						<code v-for="f in keys[target.id].pinned" :key="f">{{ f }}</code>
						<span>{{ t('ng_backup', 'The server offers now:') }}</span>
						<code v-for="key in keys[target.id].server" :key="key.fingerprint">{{ key.type }} {{ key.fingerprint }}</code>
						<div class="ngb-row">
							<NcButton variant="error" :disabled="busy" @click="pinKeys(target, keys[target.id].server)">{{ t('ng_backup', 'Accept the new host keys') }}</NcButton>
							<NcButton :disabled="busy" @click="pinKeys(target, [])">{{ t('ng_backup', 'Stop checking the host key') }}</NcButton>
						</div>
					</template>
					<template v-else-if="unpinned(keys[target.id]).length">
						<span class="ngb-err">{{ t('ng_backup', 'The server also offers host keys that are not pinned. Which key a connection gets depends on the Nextcloud version: after an upgrade, backups to this location may be refused. Pin all of the server\'s keys; all administrators are notified.') }}</span>
						<code v-for="key in unpinned(keys[target.id])" :key="key.fingerprint">{{ key.type }} {{ key.fingerprint }}</code>
						<div class="ngb-row">
							<NcButton variant="primary" :disabled="busy" @click="pinKeys(target, keys[target.id].server)">{{ t('ng_backup', 'Pin all host keys') }}</NcButton>
						</div>
					</template>
					<template v-else>
						<span class="ngb-ok">{{ t('ng_backup', 'Host key pinned and matching.') }}</span>
						<NcButton variant="tertiary" :disabled="busy" @click="pinKeys(target, [])">{{ t('ng_backup', 'Stop checking the host key') }}</NcButton>
					</template>
				</div>
			</li>
		</ul>

		<NcButton v-if="!adding" @click="startAdd()">{{ t('ng_backup', 'Add location') }}</NcButton>
		<form v-else class="ngb-form" @submit.prevent="save">
			<NcNoteCard v-if="editing" type="info">
				{{ t('ng_backup', 'Editing "{name}". The new settings must lead to the same repository, so its backups and history are kept. Leave a password field empty to keep the stored password.', { name: editing.name }) }}
			</NcNoteCard>
			<NcTextField v-model="form.name" :label="t('ng_backup', 'Name')" />
			<NcSelect v-model="backend" :options="backends" label="name" :input-label="t('ng_backup', 'Type')" :clearable="false" />
			<NcSelect v-if="backend" v-model="auth" :options="backend.auth" label="name" :input-label="t('ng_backup', 'Authentication')" :clearable="false" />
			<template v-for="field in fields" :key="field.key">
				<NcCheckboxRadioSwitch v-if="field.type === 1" v-model="form.options[field.key]">{{ field.label }}</NcCheckboxRadioSwitch>
				<NcPasswordField v-else-if="field.type === 2" v-model="form.options[field.key]" :label="field.label + (field.optional ? '' : ' *')" autocomplete="off" />
				<NcTextField v-else v-model="form.options[field.key]" :label="field.label + (field.optional ? '' : ' *')" />
			</template>
			<NcTextField v-model="form.path" :label="t('ng_backup', 'Folder on the location for the backups')" />
			<NcTextField v-model="form.maxGb" type="number" min="0" :label="t('ng_backup', 'Maximum size in GiB')"
				:helper-text="form.maxGb ? t('ng_backup', 'Backups stop at this size; administrators are warned at 80%.') : t('ng_backup', 'Empty = unlimited: everything stored is billed by your provider, and nothing stops a backup that keeps growing.')" />
			<div v-if="newKeys" class="ngb-keys">
				<template v-if="newKeys.error">
					<span class="ngb-err">{{ t('ng_backup', 'The server\'s host keys could not be read: {error}', { error: newKeys.error }) }}</span>
				</template>
				<template v-else>
					<span>{{ t('ng_backup', 'Host keys of the server. Compare them with what your provider publishes.') }}</span>
					<code v-for="key in newKeys.server" :key="key.fingerprint">{{ key.type }} {{ key.fingerprint }}</code>
					<NcCheckboxRadioSwitch v-model="newKeys.pin">{{ t('ng_backup', 'Pin these host keys: NG Backup then refuses a server with another key') }}</NcCheckboxRadioSwitch>
				</template>
			</div>
			<div class="ngb-row">
				<NcButton type="submit" variant="primary" :disabled="busy || !form.name || !backend || !auth">{{ editing ? t('ng_backup', 'Connect and save') : t('ng_backup', 'Connect and add') }}</NcButton>
				<NcButton @click="adding = false; editing = null; newKeys = null">{{ t('ng_backup', 'Cancel') }}</NcButton>
			</div>
		</form>
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
	</NcSettingsSection>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import api from '../api.js'

defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const adding = ref(false)
const editing = ref(null) // the target being edited, or null while adding
const busy = ref(false)
const error = ref('')
const backends = ref([])
const backend = ref(null)
const auth = ref(null)
const tests = reactive({})
const keys = reactive({}) // host keys per location, after a test (SFTP)
const newKeys = ref(null) // host keys of the server of a location being added (SFTP): { server, pin, error }
const form = reactive({ name: '', path: 'ng_backup', options: {}, maxGb: '' })

const matches = (k) => k.server.some((key) => k.pinned.includes(key.fingerprint))
const unpinned = (k) => k.server.filter((key) => !k.pinned.includes(key.fingerprint))
// Read again when the server changes: the keys shown must be those of the server that is added.
watch(() => [backend.value?.id, form.options.host, form.options.port], () => { newKeys.value = null })

const gib = (b) => (b / 1073741824).toFixed(1) + ' GiB'
function usageText(target) {
	if (target.storedBytes === null || target.storedBytes === undefined) {
		return target.maxBytes ? t('ng_backup', 'limit {limit}', { limit: gib(target.maxBytes) }) : t('ng_backup', 'no limit')
	}
	return target.maxBytes
		? t('ng_backup', '{used} of {limit}', { used: gib(target.storedBytes), limit: gib(target.maxBytes) })
		: t('ng_backup', '{used} stored, no limit', { used: gib(target.storedBytes) })
}
const usageClass = (target) => (target.maxBytes && target.storedBytes >= 0.8 * target.maxBytes) ? 'ngb-err' : 'ngb-muted'

// files_external DefinitionParameter: type 0 text, 1 boolean, 2 password; flag 1 = optional, 4 = hidden
const toFields = (params) => Object.entries(params || {})
	.filter(([, p]) => !(p.flags & 4))
	.map(([key, p]) => ({ key, label: p.value, type: p.type, optional: !!(p.flags & 1) }))
const fields = computed(() => [...toFields(backend.value?.parameters), ...toFields(auth.value?.parameters)])

let prefilling = false
watch(backend, (b) => {
	if (prefilling) {
		return
	}
	auth.value = b?.auth?.[0] ?? null
	form.options = {}
})

async function loadBackends() {
	if (!backends.value.length) {
		backends.value = await api.backends()
	}
}

async function startAdd() {
	error.value = ''
	editing.value = null
	newKeys.value = null
	Object.assign(form, { name: '', path: 'ng_backup', options: {}, maxGb: '' })
	backend.value = null
	adding.value = true
	try {
		await loadBackends()
	} catch (e) {
		error.value = e.message
	}
}

async function startEdit(target) {
	error.value = ''
	try {
		await confirmPassword()
		await loadBackends()
		const settings = await api.targetSettings(target.id)
		prefilling = true
		backend.value = backends.value.find((b) => b.id === settings.backend) ?? null
		auth.value = backend.value?.auth.find((a) => a.id === settings.auth) ?? null
		Object.assign(form, { name: settings.name, path: settings.path, options: Object.fromEntries(Object.entries(settings.options).map(([k, v]) => [k, v ?? ''])),
			maxGb: target.maxBytes ? String(+(target.maxBytes / 1073741824).toFixed(2)) : '' })
		await nextTick()
		prefilling = false
		editing.value = target
		adding.value = true
	} catch (e) {
		prefilling = false
		error.value = e?.message || ''
	}
}

async function save() {
	busy.value = true
	error.value = ''
	const maxGb = form.maxGb === '' ? null : Number(form.maxGb)
	try {
		await confirmPassword()
		if (editing.value) {
			// An empty password field sends null: the server keeps the stored password.
			const options = { ...form.options }
			for (const field of fields.value) {
				if (field.type === 2 && !options[field.key]) {
					options[field.key] = null
				}
			}
			await api.updateTarget(editing.value.id, { name: form.name, backend: backend.value.id, auth: auth.value.id, options, path: form.path })
			if (maxGb !== (editing.value.maxBytes ? Number((editing.value.maxBytes / 1073741824).toFixed(2)) : null)) {
				await api.setTargetLimit(editing.value.id, maxGb)
			}
			delete tests[editing.value.id]
		} else {
			if (backend.value.hostKeys && newKeys.value === null) {
				// First ask whether to pin the server's host keys; the next click adds the location.
				// The keys must be those of the server in the form when they come back: a host
				// changed while the scan ran makes the result worthless.
				const scanned = { backend: backend.value.id, host: form.options.host, port: form.options.port }
				const current = () => scanned.backend === backend.value?.id && scanned.host === form.options.host && scanned.port === form.options.port
				let result
				try {
					const r = await api.scanHostKeys(scanned.backend, { host: scanned.host, port: scanned.port })
					result = { server: r.server, pin: true, error: null }
				} catch (e) {
					result = { server: [], pin: false, error: e?.message || '' }
				}
				if (current()) {
					newKeys.value = result
				}
				return
			}
			const options = { ...form.options }
			if (newKeys.value?.pin && newKeys.value.server.length) {
				options.host_key_fingerprints = newKeys.value.server.map((key) => key.fingerprint)
			}
			await api.addTarget({ name: form.name, backend: backend.value.id, auth: auth.value.id, options, path: form.path, maxGb })
			newKeys.value = null
		}
		adding.value = false
		editing.value = null
		Object.assign(form, { name: '', path: 'ng_backup', options: {}, maxGb: '' })
		emit('changed')
	} catch (e) {
		error.value = e?.message || ''
	} finally {
		busy.value = false
	}
}

async function test(target) {
	delete keys[target.id]
	tests[target.id] = await api.testTarget(target.id)
	if (target.hostKeys !== null && target.hostKeys !== undefined) {
		try {
			keys[target.id] = await api.hostKeys(target.id)
		} catch (e) {
			keys[target.id] = { pinned: [], server: [], error: e?.message || '' }
		}
	}
}

/** Pin these keys (or none). Changing or removing pinned keys notifies all administrators. */
async function pinKeys(target, server) {
	busy.value = true
	error.value = ''
	try {
		await confirmPassword()
		await api.setHostKeys(target.id, server.map((key) => key.fingerprint))
		delete keys[target.id]
		emit('changed')
		await test(target)
	} catch (e) {
		error.value = e?.message || ''
	} finally {
		busy.value = false
	}
}

async function remove(target) {
	if (!window.confirm(t('ng_backup', 'Remove "{name}" from NG Backup? The backups on the location itself are kept.', { name: target.name }))) {
		return
	}
	try {
		await confirmPassword()
		await api.removeTarget(target.id)
	} catch (e) {
		error.value = e?.message || ''
		return
	}
	emit('changed')
}
</script>

<style scoped>
.ngb-list li { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 4px 0; }
.ngb-keys { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; flex-basis: 100%; max-width: 700px; }
.ngb-keys code { font-size: 0.9em; overflow-wrap: anywhere; }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-ok { color: var(--color-success-text); }
.ngb-err { color: var(--color-error-text); }
.ngb-form { display: flex; flex-direction: column; gap: 10px; max-width: 600px; margin-top: 12px; }
.ngb-row { display: flex; gap: 8px; }
</style>
