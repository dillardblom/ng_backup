<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ng-backup">
		<NcSettingsSection :name="t('ng_backup', 'NG Backup')"
			:description="t('ng_backup', 'Encrypted, incremental backups of this Nextcloud (database, configuration and data). Backup locations are never mounted and are not visible in Files.')">
			<NcLoadingIcon v-if="!status" :size="32" />
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		</NcSettingsSection>

		<template v-if="status">
			<div class="ngb-tabs">
				<NcButton :variant="tab === 'backup' ? 'primary' : 'secondary'" @click="tab = 'backup'">{{ t('ng_backup', 'Backup and restore') }}</NcButton>
				<NcButton :variant="tab === 'settings' ? 'primary' : 'secondary'" @click="tab = 'settings'">{{ t('ng_backup', 'Settings') }}</NcButton>
			</div>

			<template v-if="tab === 'settings'">
				<KeySetup :status="status" @changed="refresh" />
				<Locations v-if="status.key.everConfirmed" :status="status" @changed="refresh" />
			</template>
			<template v-else-if="status.key.everConfirmed">
				<template v-if="status.targets.length">
					<Backups :status="status" @changed="refresh" />
					<RestoreBrowser :status="status" @changed="refresh" />
				</template>
				<NcNoteCard v-else type="info">{{ t('ng_backup', 'Add a backup location under "Settings" first.') }}</NcNoteCard>
			</template>
			<NcNoteCard v-else type="warning">{{ t('ng_backup', 'Set up the backup key under "Settings" first.') }}</NcNoteCard>
		</template>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import api from './api.js'
import Backups from './components/Backups.vue'
import KeySetup from './components/KeySetup.vue'
import Locations from './components/Locations.vue'
import RestoreBrowser from './components/RestoreBrowser.vue'

const status = ref(null)
const error = ref('')
const tab = ref('settings')
let tabInitialized = false
let timer = null

async function refresh() {
	try {
		status.value = await api.status()
		error.value = ''
		// Default to the daily-use tab once the key is set up; stay on Settings otherwise.
		// Only decided on first load, so switching tabs ourselves isn't undone by polling.
		if (!tabInitialized) {
			tab.value = status.value.key.everConfirmed ? 'backup' : 'settings'
			tabInitialized = true
		}
	} catch (e) {
		error.value = e.message
	}
	// Poll while something runs, so progress shows up without reloading.
	clearTimeout(timer)
	if (status.value?.runs.some(r => r.status === 'running')) {
		timer = setTimeout(refresh, 4000)
	}
}

onMounted(refresh)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped>
.ng-backup :deep(.settings-section) {
	max-width: 900px;
}
.ngb-tabs {
	display: flex;
	gap: 8px;
	/* Same inline margin as NcSettingsSection, so the tabs line up with the section content */
	margin: 0 calc(var(--default-grid-baseline) * 7) 12px;
}
</style>
