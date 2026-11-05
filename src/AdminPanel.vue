<template>
	<div class="ocrflow-admin">
		<h3 class="ocrflow-admin__heading">
			{{ t('OCR engine') }}
		</h3>
		<p class="ocrflow-admin__hint">
			{{ t('The engine decides whether page images of documents leave this server. Nothing is queued until one is chosen.') }}
		</p>

		<div v-if="error" class="ocrflow-admin__error">
			<NcNoteCard type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<NcSelect
			v-model="engine"
			class="ocrflow-admin__field"
			:options="engineOptions"
			:disabled="busy"
			:clearable="false"
			:label="t('Engine')" />

		<NcNoteCard v-if="engine === 'google'" type="warning">
			{{ t('Google Cloud Vision receives a page image of every document. Files are not sent anywhere else.') }}
		</NcNoteCard>

		<h3 class="ocrflow-admin__heading">
			{{ t('Who may use OCR') }}
		</h3>
		<p class="ocrflow-admin__hint">
			{{ t('Leave empty to allow everyone. Naming groups restricts OCR to their members.') }}
		</p>
		<NcSelect
			v-model="groups"
			class="ocrflow-admin__field"
			multiple
			:options="groupOptions"
			:disabled="busy"
			:label="t('Groups')"
			:clearable="true" />

		<div class="ocrflow-admin__actions">
			<NcButton type="primary" :disabled="busy" @click="save">
				{{ busy ? t('Saving…') : t('Save') }}
			</NcButton>
			<span v-if="saved" class="ocrflow-admin__saved">
				{{ t('Saved') }}
			</span>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t as translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const BASE = '/ocs/v2.php/apps/ocrflow/api/v1'

const ENGINE_LABELS = {
	tesseract: 'tesseract (offline, nothing leaves the server)',
	google: 'google (Cloud Vision, page images are sent)',
	minimax: 'minimax (API, page images are sent)',
	none: 'none (nothing is queued)',
}

export default {
	name: 'OcrFlowAdminPanel',
	components: { NcButton, NcNoteCard, NcSelect },
	data() {
		return {
			engine: 'none',
			groups: [],
			availableGroups: [],
			busy: false,
			error: '',
			saved: false,
		}
	},
	computed: {
		engineOptions() {
			return Object.entries(ENGINE_LABELS).map(([value, label]) => ({
				value,
				label: this.t(label),
			}))
		},
		groupOptions() {
			return this.availableGroups.map((gid) => ({ value: gid, label: gid }))
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		t(text, params) {
			// The real translation function, not a passthrough: a passthrough
			// makes the whole interface English in every locale, silently.
			return translate(text, params)
		},
		async load() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.get(`${BASE}/settings`, { headers: { 'OCS-APIRequest': 'true' } })
				const settings = data?.ocs?.data ?? {}
				this.engine = settings.engine ?? 'none'
				this.groups = settings.enabled_groups ?? []
				this.availableGroups = settings.available_groups ?? []
			} catch (e) {
				this.error = `Could not read the settings: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.busy = false
			}
		},
		async save() {
			this.busy = true
			this.error = ''
			this.saved = false
			try {
				const { data } = await axios.put(
					`${BASE}/settings`,
					{ engine: this.engine, enabled_groups: this.groups },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				const saved = data?.ocs?.data ?? {}
				// Take the answer from the server: it is what the instance now
				// does, not what the page asked for.
				this.engine = saved.engine ?? this.engine
				this.groups = saved.enabled_groups ?? this.groups
				this.saved = true
			} catch (e) {
				this.error = `Could not save the settings: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.ocrflow-admin {
	max-width: 40rem;
}

.ocrflow-admin__hint {
	color: var(--color-text-maxcontrast);
}

.ocrflow-admin__heading {
	margin-top: 1.5rem;
}

.ocrflow-admin__field {
	margin-top: 0.5rem;
}

.ocrflow-admin__actions {
	display: flex;
	gap: 0.75rem;
	align-items: center;
	margin-top: 1.5rem;
}

.ocrflow-admin__saved {
	color: var(--color-text-maxcontrast);
}

.ocrflow-admin__error {
	margin-bottom: 1rem;
}
</style>
