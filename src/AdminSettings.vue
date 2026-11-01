<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="ng-backup">
		<NcSettingsSection :name="t('ng_backup', 'NG Backup')"
			:description="t('ng_backup', 'Encrypted, incremental backups of this Nextcloud (database, configuration and data). Backup locations are never mounted and are not visible in Files.')">
			<p class="ngb-feedback">
				{{ t('ng_backup', 'We would like to hear how NG Backup works for you, also when everything went fine.') }}
				<a :href="feedbackUrl" target="_blank" rel="noopener noreferrer">{{ t('ng_backup', 'Share your experience ↗') }}</a>
			</p>
			<NcLoadingIcon v-if="!status" :size="32" />
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		</NcSettingsSection>

		<template v-if="status">
			<div class="ngb-tabs">
				<NcButton :variant="tab === 'backup' ? 'primary' : 'secondary'" @click="tab = 'backup'">{{ t('ng_backup', 'Backup and restore') }}</NcButton>
				<NcButton :variant="tab === 'settings' ? 'primary' : 'secondary'" @click="tab = 'settings'">{{ t('ng_backup', 'Settings') }}</NcButton>
				<NcButton :variant="tab === 'docs' ? 'primary' : 'secondary'" @click="tab = 'docs'">{{ t('ng_backup', 'Docs') }}</NcButton>
			</div>

			<NcSettingsSection v-if="tab === 'docs'" :name="t('ng_backup', 'Documentation')">
				<ul class="ngb-docs">
					<li v-for="doc in docs" :key="doc.url">
						<a :href="doc.url" target="_blank" rel="noopener noreferrer">{{ doc.name }} ↗</a>
						<span>{{ doc.description }}</span>
					</li>
				</ul>
			</NcSettingsSection>
			<template v-else-if="tab === 'settings'">
				<KeySetup :status="status" @changed="refresh" />
				<Locations v-if="status.key.everConfirmed" :status="status" @changed="refresh" />
			</template>
			<template v-else-if="status.key.everConfirmed">
				<template v-if="status.targets.length">
					<Backups :status="status" :all-runs="allRuns" @changed="refresh" @show-all-runs="showAllRuns" />
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

// Opens the experience report form in .github/ISSUE_TEMPLATE
const feedbackUrl = 'https://github.com/dillardblom/ng_backup/issues/new?template=experience.yml'

const repoUrl = 'https://github.com/dillardblom/ng_backup'
const docs = [
	{
		name: t('ng_backup', 'How-to'),
		description: t('ng_backup', 'Step by step: from first install to a tested restore.'),
		url: repoUrl + '/blob/main/docs/HOWTO.md',
	},
	{
		name: t('ng_backup', 'Disaster recovery'),
		description: t('ng_backup', 'Your server is gone: get Nextcloud back on a new server from a backup location.'),
		url: repoUrl + '/blob/main/docs/DISASTER-RECOVERY.md',
	},
	{
		name: t('ng_backup', 'Roadmap'),
		description: t('ng_backup', 'What is planned for the next versions.'),
		url: repoUrl + '#roadmap',
	},
]

const status = ref(null)
const allRuns = ref(false) // runs older than the last days too
const error = ref('')
const tab = ref('settings')
let tabInitialized = false
let timer = null

function showAllRuns(all) {
	allRuns.value = all
	refresh()
}

async function refresh() {
	try {
		status.value = await api.status(allRuns.value)
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
/* NcSettingsSection sets its own width (at most 900px): the runs table needs more */
.ng-backup :deep(.settings-section) {
	width: min(1400px, 100% - var(--default-grid-baseline) * 7 * 2);
	max-width: none;
}
.ngb-feedback {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}
.ngb-feedback a {
	color: var(--color-primary-element);
	text-decoration: underline;
}
.ngb-docs li {
	margin-bottom: 8px;
}
.ngb-docs a {
	color: var(--color-primary-element);
	text-decoration: underline;
	margin-right: 8px;
}
.ngb-docs span {
	color: var(--color-text-maxcontrast);
}
.ngb-tabs {
	display: flex;
	gap: 8px;
	/* Same inline margin as NcSettingsSection, so the tabs line up with the section content */
	margin: 0 calc(var(--default-grid-baseline) * 7) 12px;
}
</style>
