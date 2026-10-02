<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Backup key and recovery kit')"
		:description="t('ng_backup', 'All backups are encrypted with one key. The server keeps it for scheduled backups; the recovery kit, protected by your passphrase, is what you need to restore on a new server.')">
		<!-- 1. Create the key -->
		<form v-if="!status.key.initialized" class="ngb-form" @submit.prevent="createKey">
			<NcPasswordField v-model="pass1" :label="t('ng_backup', 'Passphrase (at least 12 characters)')" autocomplete="new-password" />
			<NcPasswordField v-model="pass2" :label="t('ng_backup', 'Repeat passphrase')" autocomplete="new-password"
				:error="pass2 !== '' && pass1 !== pass2" :helper-text="pass2 !== '' && pass1 !== pass2 ? t('ng_backup', 'The passphrases do not match') : ''" />
			<NcButton type="submit" variant="primary" :disabled="busy || pass1.length < 12 || pass1 !== pass2">
				{{ t('ng_backup', 'Create backup key') }}
			</NcButton>
		</form>

		<template v-else>
			<p>{{ t('ng_backup', 'Key fingerprint: {fingerprint}', { fingerprint: status.key.fingerprint }) }}</p>

			<!-- 2. Download the kit and confirm -->
			<div v-if="!status.key.confirmation" class="ngb-confirm">
				<NcNoteCard type="warning">
					{{ t('ng_backup', 'Before the first backup: download the recovery kit and store it, together with your passphrase, somewhere safe outside this server.') }}
				</NcNoteCard>
				<NcButton @click="downloadKit">
					{{ t('ng_backup', 'Download recovery kit') }}
				</NcButton>
				<NcCheckboxRadioSwitch v-model="accepted" :disabled="!downloaded">
					{{ status.statement }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="primary" :disabled="!downloaded || !accepted || busy" @click="confirm">
					{{ status.confirmationPhrase }}
				</NcButton>
			</div>
			<p v-else>
				{{ t('ng_backup', 'Recovery kit confirmed by {user} on {date}.', { user: status.key.confirmation.uid, date: new Date(status.key.confirmation.time * 1000).toLocaleString() }) }}
				<NcButton variant="tertiary" @click="downloadKit">{{ t('ng_backup', 'Download the kit again') }}</NcButton>
				<span class="ngb-muted">{{ t('ng_backup', 'Requires your password; all administrators are notified.') }}</span>
			</p>
		</template>
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
	</NcSettingsSection>
</template>

<script setup>
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import api from '../api.js'

const props = defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const pass1 = ref('')
const pass2 = ref('')
const downloaded = ref(false)
const accepted = ref(false)
const busy = ref(false)
const error = ref('')
const kitUrl = api.kitUrl()

async function downloadKit() {
	error.value = ''
	try {
		await confirmPassword()
		window.location.href = kitUrl
		downloaded.value = true
	} catch (e) {
		error.value = e?.message || ''
	}
}

async function createKey() {
	busy.value = true
	error.value = ''
	try {
		await confirmPassword()
		await api.initKey(pass1.value)
		pass1.value = pass2.value = ''
		emit('changed')
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}

async function confirm() {
	busy.value = true
	error.value = ''
	try {
		await api.confirmKit(accepted.value, props.status.confirmationPhrase)
		emit('changed')
	} catch (e) {
		error.value = e.message
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.ngb-muted { color: var(--color-text-maxcontrast); margin-inline-start: 8px; }
.ngb-form, .ngb-confirm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;
}
</style>
