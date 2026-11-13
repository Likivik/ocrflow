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
			{{ t('Outcomes') }}
		</h3>
		<p class="ocrflow-admin__hint">
			{{ t('Every file this app has decided about, by what happened to it. One list, five tabs — no row is invisible and no row is counted twice.') }}
		</p>

		<div v-if="outcomes.error" class="ocrflow-admin__error">
			<NcNoteCard type="error">
				{{ outcomes.error }}
			</NcNoteCard>
		</div>

		<p v-else-if="!outcomesLoaded" class="ocrflow-admin__hint">
			{{ t('Loading…') }}
		</p>

		<template v-else>
			<div class="ocrflow-tabs" role="tablist">
				<button
					v-for="tab in TABS"
					:key="tab.verdict"
					:class="['ocrflow-tabs__tab', { 'ocrflow-tabs__tab--active': tab.verdict === verdict }]"
					type="button"
					role="tab"
					:aria-selected="tab.verdict === verdict"
					@click="switchTab(tab.verdict)">
					{{ t(tab.label) }}
					<span v-if="summary[tab.verdict] > 0" class="ocrflow-admin__badge">{{ summary[tab.verdict] }}</span>
				</button>
			</div>

			<p v-if="verdict === 'needs_fix'" class="ocrflow-admin__hint">
				{{ t('Files that failed for a reason a retry cannot fix — a broken file, missing engine credentials, a rejected worker secret. Fix the cause, then retry the file.') }}
			</p>
			<p v-else-if="verdict === 'redo'" class="ocrflow-admin__hint">
				{{ t('Files you may want to OCR again: stamped below the current engine bar, suspected of being OCR’d elsewhere, or with a stashed result you must apply or discard. Nothing here re-OCRs automatically.') }}
			</p>
			<p v-else-if="verdict === 'wait'" class="ocrflow-admin__hint">
				{{ t('These failures retried until the attempt limit; they are terminal and nothing retries them automatically. Requeue a file by hand if the cause is gone.') }}
			</p>
			<p v-else-if="verdict === 'settled'" class="ocrflow-admin__hint">
				{{ t('Decided and left alone: already OCR’d at or above the bar, or nothing to OCR.') }}
				{{ t('{done} of {total} are files this app OCR’d itself.', { done: summary.done, total: summary.settled }) }}
			</p>
			<p v-else-if="verdict === 'dropped'" class="ocrflow-admin__hint">
				{{ t('Results that arrived for jobs nobody is waiting for anymore.') }}
			</p>

			<p v-if="outcomes.rows.length === 0 && codeFilter === ''" class="ocrflow-admin__hint ocrflow-admin__empty">
				{{ t('nothing here') }}
			</p>

			<template v-else>
				<NcSelect
					v-model="codeFilter"
					class="ocrflow-admin__field"
					:options="codeOptions"
					:reduce="(option) => option.value"
					:disabled="outcomesBusy"
					:clearable="true"
					:placeholder="t('Filter by reason')"
					:label="t('Reason')" />

				<div
					v-for="row in outcomes.rows"
					:key="row.origin + ':' + row.id"
					class="ocrflow-attention">
					<div class="ocrflow-attention__head">
						<label v-if="verdict === 'redo'" class="ocrflow-attention__select">
							<input
								type="checkbox"
								:checked="selected.includes(row.file_id)"
								:disabled="outcomesBusy"
								@change="toggleSelected(row.file_id)">
						</label>
						<span class="ocrflow-attention__path" :title="row.path">{{ row.path }}</span>
						<span class="ocrflow-attention__code">
							{{ row.label }}
							<span v-if="row.pending" class="ocrflow-attention__stash">{{ t('stashed result') }}</span>
							<span v-else-if="row.done" class="ocrflow-attention__stash">{{ t('done by this app') }}</span>
						</span>
					</div>
					<p class="ocrflow-attention__fix">
						{{ row.fix }}
						<span v-if="row.note" class="ocrflow-attention__engine">{{ row.note }}</span>
						<span v-if="row.engine" class="ocrflow-attention__engine">
							{{ t('(OCR’d by {engine} {version})', { engine: row.engine, version: row.engine_version ?? t('version unknown') }) }}
						</span>
					</p>
					<p v-if="row.error" class="ocrflow-attention__error">
						<a class="ocrflow-attention__toggle" href="#" @click.prevent="expanded = expanded === row.id ? null : row.id">
							{{ expanded === row.id ? t('Hide the error text') : t('Show the error text') }}
						</a>
						<span v-if="expanded === row.id" class="ocrflow-attention__prose">{{ row.error }}</span>
					</p>
					<div class="ocrflow-attention__actions">
						<span class="ocrflow-attention__meta">
							<template v-if="verdict === 'needs_fix' || verdict === 'wait'">
								{{ t('Attempts: {n}', { n: row.attempts }) }}
							</template>
							<template v-else-if="row.pages !== null && row.pages !== undefined">
								{{ t('{n} pages', { n: row.pages }) }}
							</template>
							<template v-else-if="verdict === 'redo'">
								{{ t('pages unknown') }}
							</template>
						</span>

						<NcButton
							v-if="row.pending"
							type="primary"
							:disabled="outcomesBusy"
							@click="applyStash(row)">
							{{ t('Apply') }}
						</NcButton>
						<NcButton
							v-if="row.pending"
							type="secondary"
							:disabled="outcomesBusy"
							@click="discardStash(row)">
							{{ t('Discard') }}
						</NcButton>
						<NcButton
							v-if="verdict === 'needs_fix'"
							type="secondary"
							:disabled="outcomesBusy"
							@click="retryOne(row)">
							{{ t('Retry') }}
						</NcButton>
					</div>
				</div>

				<div v-if="verdict === 'redo'" class="ocrflow-admin__actions">
					<NcButton
						type="warning"
						:disabled="outcomesBusy || selected.length === 0"
						@click="confirmRedo">
						{{ t('Re-OCR the selection') }}
					</NcButton>
					<span class="ocrflow-attention__meta">{{ selectionSummary }}</span>
				</div>

				<div v-if="verdict === 'needs_fix'" class="ocrflow-admin__actions">
					<NcButton
						type="warning"
						:disabled="outcomesBusy || outcomes.rows.length === 0"
						@click="confirmRetryAll">
						{{ outcomesBusy ? t('Retrying…') : t('Retry all') }}
					</NcButton>
				</div>
			</template>
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

// The five tabs, in panel order; the verdict is what the endpoint takes.
const TABS = [
	{ verdict: 'needs_fix', label: 'Needs you' },
	{ verdict: 'redo', label: 'Decide' },
	{ verdict: 'wait', label: 'Waiting' },
	{ verdict: 'settled', label: 'Settled' },
	{ verdict: 'dropped', label: 'Dropped' },
]

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
			TABS,
			verdict: 'needs_fix',
			outcomes: { rows: [], total: 0, cap: 500, codes: {}, pricing: null },
			summary: { needs_fix: 0, redo: 0, wait: 0, settled: 0, dropped: 0, done: 0 },
			outcomesLoaded: false,
			outcomesBusy: false,
			selected: [],
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
			return Object.entries(this.outcomes.codes ?? {}).map(([value, label]) => ({
				value,
				label: `${label} (${value})`,
			}))
		},
		// The honest estimate shown BEFORE the click: files and pages come
		// from the selected rows; money only when every page count is
		// known AND the instance configured a price. Otherwise "unknown".
		selectionSummary() {
			if (this.selected.length === 0) {
				return this.t('select files to re-OCR')
			}
			const rows = this.outcomes.rows.filter((row) => this.selected.includes(row.file_id))
			const pages = rows.reduce((sum, row) => sum + (row.pages ?? 0), 0)
			const pagesKnown = rows.every((row) => row.pages !== null && row.pages !== undefined)
			const price = this.outcomes.pricing?.per_1000_pages
			if (pagesKnown && price) {
				return this.t('{n} files, {pages} pages, ~${cost}', {
					n: rows.length,
					pages,
					cost: (pages * price / 1000).toFixed(2),
				})
			}
			if (pagesKnown) {
				return this.t('{n} files, {pages} pages, cost unknown', { n: rows.length, pages })
			}
			return this.t('{n} files, pages unknown, cost unknown', { n: rows.length })
		},
	},
	mounted() {
		this.load()
		this.loadOutcomes()
	},
	watch: {
		codeFilter() {
			this.loadOutcomes()
		},
	},
	methods: {
		t(text, params) {
			// The real translation function, not a passthrough: a passthrough
			// makes the whole interface English in every locale, silently.
			return translate(text, params)
		},
		switchTab(verdict) {
			this.verdict = verdict
			this.codeFilter = ''
			this.selected = []
			this.expanded = null
			this.loadOutcomes()
		},
		toggleSelected(fileId) {
			this.selected = this.selected.includes(fileId)
				? this.selected.filter((id) => id !== fileId)
				: [...this.selected, fileId]
		},
		async loadOutcomes() {
			this.outcomesBusy = true
			this.outcomes.error = ''
			try {
				const params = { limit: 50, verdict: this.verdict }
				if (this.codeFilter) {
					params.code = this.codeFilter
				}
				const { data } = await axios.get(`${BASE}/outcomes`, {
					params,
					headers: { 'OCS-APIRequest': 'true' },
				})
				const result = data?.ocs?.data ?? {}
				this.outcomes = {
					rows: result.rows ?? [],
					total: result.total ?? 0,
					cap: result.cap ?? 500,
					codes: result.codes ?? {},
					pricing: result.pricing ?? null,
					error: '',
				}
				this.summary = result.summary ?? this.summary
				this.expanded = null
			} catch (e) {
				this.outcomes.error = `Could not read the outcomes: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.outcomesBusy = false
				this.outcomesLoaded = true
			}
		},
		async retryOne(row) {
			this.outcomesBusy = true
			try {
				await axios.post(
					`${BASE}/attention/retry`,
					{ id: row.id },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
			} catch (e) {
				this.outcomes.error = `Could not retry ${row.path}: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.outcomesBusy = false
			}
			// The server answer is not trusted over its own list: reload.
			await this.loadOutcomes()
		},
		async confirmRetryAll() {
			// Retrying can spend real money on paid engines, so it is behind
			// a confirm, and the cap is echoed so the operator knows what one
			// click may cost.
			const cap = this.outcomes.cap
			const total = this.outcomes.total
			const ok = window.confirm(
				this.t('Retry up to {cap} files ({total} waiting)? Each retry queues a new OCR run, which can spend money on paid engines.', { cap, total }),
			)
			if (!ok) {
				return
			}
			this.outcomesBusy = true
			try {
				await axios.post(
					`${BASE}/attention/retry`,
					{ all: true },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
			} catch (e) {
				this.outcomes.error = `Could not retry all: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.outcomesBusy = false
			}
			await this.loadOutcomes()
		},
		async confirmRedo() {
			// The estimate was already shown next to the button ("N files,
			// M pages, ~$X" or "unknown") — the confirm repeats it so the
			// click that spends money is an informed one.
			const ok = window.confirm(
				this.t('Re-OCR the selection ({summary})? This queues new OCR runs, which can spend money on paid engines.', { summary: this.selectionSummary }),
			)
			if (!ok) {
				return
			}
			this.outcomesBusy = true
			try {
				await axios.post(
					`${BASE}/outcomes/redo`,
					{ ids: this.selected },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
			} catch (e) {
				this.outcomes.error = `Could not re-OCR the selection: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.outcomesBusy = false
			}
			await this.loadOutcomes()
		},
		async applyStash(row) {
			this.outcomesBusy = true
			try {
				const { data } = await axios.post(
					`${BASE}/outcomes/apply`,
					{ id: row.id },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				const result = data?.ocs?.data ?? {}
				if (result.status !== 'applied') {
					// The refusal carries the warning a human reads — e.g.
					// versioning being unavailable — and the stash stays.
					this.outcomes.error = result.warning ?? 'the stashed result could not be applied'
				}
			} catch (e) {
				this.outcomes.error = `Could not apply the stashed result: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.outcomesBusy = false
			}
			await this.loadOutcomes()
		},
		async discardStash(row) {
			this.outcomesBusy = true
			try {
				await axios.post(
					`${BASE}/outcomes/discard`,
					{ id: row.id },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
			} catch (e) {
				this.outcomes.error = `Could not discard the stashed result: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.outcomesBusy = false
			}
			await this.loadOutcomes()
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

.ocrflow-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	margin-top: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.ocrflow-tabs__tab {
	padding: 0.4rem 0.75rem;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.ocrflow-tabs__tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: bold;
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

.ocrflow-attention__select {
	flex: 0 0 auto;
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

.ocrflow-attention__stash {
	display: inline-block;
	margin-left: 0.5rem;
	padding: 0 0.25rem;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-warning, #e9322d);
	color: #fff;
	font-size: 0.8em;
}

.ocrflow-attention__fix {
	color: var(--color-text-maxcontrast);
	margin: 0.25rem 0 0;
}

.ocrflow-attention__engine {
	font-style: italic;
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
