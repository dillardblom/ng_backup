<!--
  - SPDX-FileCopyrightText: 2026 Dillard Blom
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('ng_backup', 'Bin')"
		:description="t('ng_backup', 'Restore points that were removed, most of them by the retention policy. They can be brought back until they are deleted permanently.')">
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="empty" type="info">{{ t('ng_backup', 'The bin is empty.') }}</NcNoteCard>

		<template v-for="target in status.targets" :key="'trash' + target.id">
			<div v-if="trash[target.id]?.length" class="ngb-trash">
				<h3>{{ status.targets.length > 1 ? target.name : t('ng_backup', 'Removed restore points') }}</h3>
				<ul>
					<li v-for="item in trash[target.id]" :key="item.id">
						{{ item.time ? formatDateTime(item.time) : item.id }}{{ item.label ? ' – ' + item.label : '' }}
						<span class="ngb-muted">{{ t('ng_backup', 'removed by {by}, deleted after {date}', { by: item.by, date: formatDate(item.deleteAfter) }) }}</span>
						<button v-if="item.by === RETENTION"
							class="ngb-info"
							:aria-expanded="retentionInfo ? 'true' : 'false'"
							:aria-label="t('ng_backup', 'What does this mean?')"
							:title="t('ng_backup', 'What does this mean?')"
							@click="retentionInfo = !retentionInfo">
							ⓘ
						</button>
						<NcButton variant="tertiary" @click="untrash(target, item)">{{ t('ng_backup', 'Restore') }}</NcButton>
					</li>
				</ul>
				<NcNoteCard v-if="retentionInfo && trash[target.id].some(item => item.by === RETENTION)" type="info">
					{{ n('ng_backup',
						'The retention policy keeps the newest restore points, and for older days, weeks and months only the last restore point of each. It moves the others to this bin, where they stay for %n day before they are deleted permanently. Until then you can bring one back with "Restore". Nothing was lost by accident.',
						'The retention policy keeps the newest restore points, and for older days, weeks and months only the last restore point of each. It moves the others to this bin, where they stay for %n days before they are deleted permanently. Until then you can bring one back with "Restore". Nothing was lost by accident.',
						status.key.deleteDelayDays) }}
				</NcNoteCard>
			</div>
		</template>
	</NcSettingsSection>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { n, t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import api from '../api.js'
import { formatDate, formatDateTime } from '../format.js'

const props = defineProps({ status: { type: Object, required: true } })
const emit = defineEmits(['changed'])

// "by" of a restore point the weekly cleanup removed (PruneService)
const RETENTION = 'retention policy'
const retentionInfo = ref(false)
const trash = reactive({})
const loading = ref(true)
const error = ref('')
const empty = computed(() => props.status.targets.every(target => !trash[target.id]?.length))

async function loadTrash() {
	for (const target of props.status.targets) {
		try {
			trash[target.id] = await api.trash(target.id)
		} catch (e) {
			trash[target.id] = []
		}
	}
	loading.value = false
}
watch(() => props.status.targets.map(tg => tg.id).join(','), loadTrash, { immediate: true })

async function untrash(target, item) {
	error.value = ''
	try {
		await confirmPassword()
		await api.untrash(target.id, item.id)
		await loadTrash()
		emit('changed')
	} catch (e) {
		error.value = e?.message || ''
	}
}
</script>

<style scoped>
.ngb-info { background: none; border: none; padding: 0 4px; min-height: auto; cursor: pointer; color: var(--color-primary-element); }
.ngb-muted { color: var(--color-text-maxcontrast); }
.ngb-trash { margin: 12px 0; }
.ngb-trash h3 { font-weight: bold; }
</style>
