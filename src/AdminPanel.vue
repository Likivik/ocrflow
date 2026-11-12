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
			:reduce="(option) => option.value"
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
			:reduce="(option) => option.value"
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

		<h3 class="ocrflow-admin__heading">
			{{ t('Needs attention') }}
			<span v-if="attention.total > 0" class="ocrflow-admin__badge">{{ attention.total }}</span>
		</h3>
		<p class="ocrflow-admin__hint">
			{{ t('Files that failed for a reason a retry cannot fix — a broken file, missing engine credentials, a rejected worker secret. Fix the cause, then retry the file.') }}
		</p>

		<div v-if="attention.error" class="ocrflow-admin__error">
			<NcNoteCard type="error">
				{{ attention.error }}
			</NcNoteCard>
		</div>

		<p v-else-if="!attentionLoaded" class="ocrflow-admin__hint">
			{{ t('Loading…') }}
		</p>

		<p v-else-if="attention.rows.length === 0 && codeFilter === ''" class="ocrflow-admin__hint ocrflow-admin__empty">
			{{ t('nothing needs you') }}
		</p>

		<template v-else>
			<NcSelect
				v-model="codeFilter"
				class="ocrflow-admin__field"
				:options="codeOptions"
				:reduce="(option) => option.value"
				:disabled="attentionBusy"
				:clearable="true"
				:placeholder="t('Filter by reason')"
				:label="t('Reason')" />

			<div
				v-for="row in attention.rows"
				:key="row.id"
				class="ocrflow-attention">
				<div class="ocrflow-attention__head">
					<span class="ocrflow-attention__path" :title="row.path">{{ row.path }}</span>
					<span class="ocrflow-attention__code">{{ row.label }}</span>
				</div>
				<p class="ocrflow-attention__fix">{{ row.fix }}</p>
				<p v-if="row.error" class="ocrflow-attention__error">
					<a class="ocrflow-attention__toggle" href="#" @click.prevent="expanded = expanded === row.id ? null : row.id">
						{{ expanded === row.id ? t('Hide the error text') : t('Show the error text') }}
					</a>
					<span v-if="expanded === row.id" class="ocrflow-attention__prose">{{ row.error }}</span>
				</p>
				<div class="ocrflow-attention__actions">
					<span class="ocrflow-attention__meta">
						{{ t('Attempts: {n}', { n: row.attempts }) }}
					</span>
					<NcButton
						type="secondary"
						:disabled="attentionBusy"
						@click="retryOne(row)">
						{{ t('Retry') }}
					</NcButton>
				</div>
			</div>

			<div class="ocrflow-admin__actions">
				<NcButton
					type="warning"
					:disabled="attentionBusy || attention.rows.length === 0"
					@click="confirmRetryAll">
					{{ attentionBusy ? t('Retrying…') : t('Retry all') }}
				</NcButton>
			</div>
		</template>
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
			attention: { rows: [], total: 0, cap: 500, codes: {} },
			attentionLoaded: false,
			attentionBusy: false,
			codeFilter: '',
			expanded: null,
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
		codeOptions() {
			return Object.entries(this.attention.codes ?? {}).map(([value, label]) => ({
				value,
				label: `${label} (${value})`,
			}))
		},
	},
	mounted() {
		this.load()
		this.loadAttention()
	},
	watch: {
		codeFilter() {
			this.loadAttention()
		},
	},
	methods: {
		t(text, params) {
			// The real translation function, not a passthrough: a passthrough
			// makes the whole interface English in every locale, silently.
			return translate(text, params)
		},
		async loadAttention() {
			this.attentionBusy = true
			this.attention.error = ''
			try {
				const params = { limit: 50 }
				if (this.codeFilter) {
					params.code = this.codeFilter
				}
				const { data } = await axios.get(`${BASE}/attention`, {
					params,
					headers: { 'OCS-APIRequest': 'true' },
				})
				const result = data?.ocs?.data ?? {}
				this.attention = {
					rows: result.rows ?? [],
					total: result.total ?? 0,
					cap: result.cap ?? 500,
					codes: result.codes ?? {},
					error: '',
				}
				this.expanded = null
			} catch (e) {
				this.attention.error = `Could not read the attention list: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.attentionBusy = false
				this.attentionLoaded = true
			}
		},
		async retryOne(row) {
			this.attentionBusy = true
			try {
				await axios.post(
					`${BASE}/attention/retry`,
					{ id: row.id },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
			} catch (e) {
				this.attention.error = `Could not retry ${row.path}: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.attentionBusy = false
			}
			// The server answer is not trusted over its own list: reload.
			await this.loadAttention()
		},
		async confirmRetryAll() {
			// Retrying can spend real money on paid engines, so it is behind
			// a confirm, and the cap is echoed so the operator knows what one
			// click may cost.
			const cap = this.attention.cap
			const total = this.attention.total
			const ok = window.confirm(
				this.t('Retry up to {cap} files ({total} waiting)? Each retry queues a new OCR run, which can spend money on paid engines.', { cap, total }),
			)
			if (!ok) {
				return
			}
			this.attentionBusy = true
			try {
				await axios.post(
					`${BASE}/attention/retry`,
					{ all: true },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
			} catch (e) {
				this.attention.error = `Could not retry all: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.attentionBusy = false
			}
			await this.loadAttention()
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

.ocrflow-admin__badge {
	display: inline-block;
	margin-left: 0.5rem;
	padding: 0 0.5rem;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.8em;
}

.ocrflow-admin__empty {
	font-style: italic;
}

.ocrflow-attention {
	border-top: 1px solid var(--color-border);
	padding: 0.75rem 0;
}

.ocrflow-attention__head {
	display: flex;
	gap: 1rem;
	align-items: baseline;
}

.ocrflow-attention__path {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.ocrflow-attention__code {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	white-space: nowrap;
}

.ocrflow-attention__fix {
	color: var(--color-text-maxcontrast);
	margin: 0.25rem 0 0;
}

.ocrflow-attention__error {
	margin: 0.25rem 0 0;
}

.ocrflow-attention__toggle {
	font-size: 0.9em;
}

.ocrflow-attention__prose {
	display: block;
	margin-top: 0.25rem;
	padding: 0.5rem;
	background: var(--color-background-dark, var(--color-main-background-blur));
	font-family: monospace;
	font-size: 0.85em;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.ocrflow-attention__actions {
	display: flex;
	gap: 1rem;
	align-items: center;
	margin-top: 0.5rem;
}

.ocrflow-attention__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
