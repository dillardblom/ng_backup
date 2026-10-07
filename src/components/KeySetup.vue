<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Backup key and recovery kit')"
		:description="t('ng_backup', 'All backups are encrypted with one key. The server keeps it for scheduled backups. To restore on a new server you need the recovery kit and one of the passphrases.')">
		<!-- 1. Create the key -->
		<form v-if="!status.key.initialized" class="ngb-form" @submit.prevent="createKey">
			<NcTextField v-model="label" :label="t('ng_backup', 'Who keeps this passphrase (e.g. Safe, CTO)')" />
			<NcPasswordField v-model="pass1" :label="t('ng_backup', 'Passphrase (at least 12 characters)')" autocomplete="new-password" />
			<NcPasswordField v-model="pass2" :label="t('ng_backup', 'Repeat passphrase')" autocomplete="new-password"
				:error="pass2 !== '' && pass1 !== pass2" :helper-text="pass2 !== '' && pass1 !== pass2 ? t('ng_backup', 'The passphrases do not match') : ''" />
			<NcTextField v-model="delay" type="number" min="1" max="365"
				:label="t('ng_backup', 'Days a removed backup stays in the trash before it is really deleted')"
				:helper-text="t('ng_backup', 'Protects against a malicious or mistaken cleanup. Cannot be changed later.')" />
			<NcButton type="submit" variant="primary" :disabled="busy || pass1.length < 12 || pass1 !== pass2 || !(delay >= 1 && delay <= 365)">
				{{ t('ng_backup', 'Create backup key') }}
			</NcButton>
		</form>

		<template v-else>
			<p>
				{{ t('ng_backup', 'Key fingerprint: {fingerprint}', { fingerprint: status.key.fingerprint }) }} ·
				{{ t('ng_backup', 'Removed backups are kept in the trash for {days} days', { days: status.key.deleteDelayDays }) }}
			</p>

			<!-- Passphrase slots -->
			<h3>{{ t('ng_backup', 'Passphrases') }}</h3>
			<p class="ngb-muted">
				{{ t('ng_backup', 'Up to {max} passphrases can open the key, so one person leaving does not lock you out. For example: one in the safe, one with the CTO, one with the head of IT. Everyone keeps the same recovery kit and only knows their own passphrase.', { max: status.key.maxSlots }) }}
			</p>
			<table class="ngb-table">
				<tbody>
					<tr v-for="slot in status.key.slots" :key="slot.slot">
						<td>{{ t('ng_backup', 'Slot {n}', { n: slot.slot }) }}</td>
						<td><strong>{{ slot.label }}</strong></td>
						<td class="ngb-muted">{{ slot.created ? new Date(slot.created * 1000).toLocaleDateString() : '' }}</td>
						<td class="ngb-actions">
							<NcButton variant="tertiary" @click="openSlotForm(slot)">{{ t('ng_backup', 'New passphrase') }}</NcButton>
							<NcButton v-if="status.key.slots.length > 1" variant="tertiary" @click="removeSlot(slot)">{{ t('ng_backup', 'Remove') }}</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton v-if="!slotForm && status.key.slots.length < status.key.maxSlots" @click="openSlotForm(null)">{{ t('ng_backup', 'Add passphrase') }}</NcButton>
			<form v-if="slotForm" class="ngb-form" @submit.prevent="saveSlot">
				<NcTextField v-model="slotForm.label" :label="t('ng_backup', 'Who keeps this passphrase')" />
				<NcPasswordField v-model="slotForm.pass1" :label="t('ng_backup', 'Passphrase (at least 12 characters)')" autocomplete="new-password" />
				<NcPasswordField v-model="slotForm.pass2" :label="t('ng_backup', 'Repeat passphrase')" autocomplete="new-password" :error="slotForm.pass2 !== '' && slotForm.pass1 !== slotForm.pass2" />
				<div class="ngb-row">
					<NcButton type="submit" variant="primary" :disabled="busy || slotForm.pass1.length < 12 || slotForm.pass1 !== slotForm.pass2">{{ t('ng_backup', 'Save') }}</NcButton>
					<NcButton @click="slotForm = null">{{ t('ng_backup', 'Cancel') }}</NcButton>
				</div>
			</form>

			<!-- Recovery kit -->
			<h3>{{ t('ng_backup', 'Recovery kit') }}</h3>
			<div v-if="!status.key.confirmation" class="ngb-confirm">
				<NcNoteCard :type="status.key.everConfirmed ? 'error' : 'warning'">
					{{ status.key.everConfirmed
						? t('ng_backup', 'The passphrases changed: download the new recovery kit, give a copy to every passphrase holder and confirm. Backups keep running meanwhile.')
						: t('ng_backup', 'Before the first backup: download the recovery kit and store it, together with the passphrase, somewhere safe outside this server.') }}
				</NcNoteCard>
				<NcButton @click="downloadKit">{{ t('ng_backup', 'Download recovery kit') }}</NcButton>
				<NcTextField v-model="code" :disabled="!downloaded" :label="t('ng_backup', 'Confirmation code from the kit file')"
					:helper-text="t('ng_backup', 'Open the downloaded file and type the value of confirmation_code, e.g. ABCD-2345.')" />
				<NcCheckboxRadioSwitch v-model="accepted" :disabled="!downloaded">{{ status.statement }}</NcCheckboxRadioSwitch>
				<NcButton variant="primary" :disabled="!downloaded || !accepted || code.length < 8 || busy" @click="confirm">{{ status.confirmationPhrase }}</NcButton>
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
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import api from '../api.js'

const props = defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const label = ref('')
const pass1 = ref('')
const pass2 = ref('')
const delay = ref(props.status.key.deleteDelayDays)
const slotForm = ref(null)
const downloaded = ref(false)
const accepted = ref(false)
const code = ref('')
const busy = ref(false)
const error = ref('')
const kitUrl = api.kitUrl()

/** Run fn after a password confirmation; report errors in the card. */
async function guarded(fn) {
	busy.value = true
	error.value = ''
	try {
		await confirmPassword()
		await fn()
		emit('changed')
	} catch (e) {
		error.value = e?.message || ''
	} finally {
		busy.value = false
	}
}

const createKey = () => guarded(async () => {
	await api.initKey(pass1.value, Number(delay.value), label.value || 'Slot 1')
	pass1.value = pass2.value = ''
})

const downloadKit = () => guarded(async () => {
	window.location.href = kitUrl
	downloaded.value = true
})

function openSlotForm(slot) {
	slotForm.value = { slot: slot?.slot ?? null, label: slot?.label ?? '', pass1: '', pass2: '' }
}

const saveSlot = () => guarded(async () => {
	const f = slotForm.value
	if (f.slot === null) {
		await api.addSlot(f.pass1, f.label || 'Slot')
	} else {
		await api.replaceSlot(f.slot, f.pass1, f.label || null)
	}
	slotForm.value = null
	downloaded.value = accepted.value = false
})

const removeSlot = (slot) => guarded(async () => {
	await api.removeSlot(slot.slot)
	downloaded.value = accepted.value = false
})

const confirm = () => guarded(async () => {
	await api.confirmKit(accepted.value, props.status.confirmationPhrase, code.value)
	code.value = ''
})
</script>

<style scoped>
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-form, .ngb-confirm { display: flex; flex-direction: column; gap: 12px; max-width: 600px; margin: 8px 0; }
.ngb-row { display: flex; gap: 8px; }
.ngb-table td { padding: 2px 12px 2px 0; }
.ngb-actions { display: flex; gap: 4px; }
h3 { margin-top: 16px; font-weight: bold; }
</style>
