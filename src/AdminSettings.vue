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
			<KeySetup :status="status" @changed="refresh" />
			<template v-if="status.key.confirmation">
				<Locations :status="status" @changed="refresh" />
				<Backups v-if="status.targets.length" :status="status" @changed="refresh" />
				<RestoreBrowser v-if="status.targets.length" :status="status" @changed="refresh" />
			</template>
		</template>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
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
let timer = null

async function refresh() {
	try {
		status.value = await api.status()
		error.value = ''
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
</style>
