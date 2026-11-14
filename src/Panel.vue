<template>
	<div class="ocrflow-panel">
		<p class="ocrflow-panel__intro">
			{{ intro }}
		</p>

		<div v-if="!engineConfigured" class="ocrflow-panel__notice">
			<NcNoteCard type="warning">
				{{ t('OCR is not configured on this instance yet, so nothing is queued. Ask an administrator to choose an engine.') }}
			</NcNoteCard>
		</div>

		<div v-if="error" class="ocrflow-panel__error">
			<NcNoteCard type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<h3 class="ocrflow-panel__heading">
			{{ t('Your files that need attention') }}
		</h3>
		<NcNoteCard v-if="mineError" type="error">
			{{ t('Could not read your files: {reason}', { reason: mineError }) }}
		</NcNoteCard>
		<p v-else-if="!mineLoaded" class="ocrflow-panel__empty">
			{{ t('Reading your files…') }}
		</p>
		<ul v-else-if="mineRows.length" class="ocrflow-panel__mine">
			<li v-for="row in mineRows" :key="rowKey(row)" class="ocrflow-panel__mine-row">
				<div class="ocrflow-panel__mine-text">
					<span class="ocrflow-panel__mine-path" :title="row.path">{{ shortPath(row.path) }}</span>
					<span class="ocrflow-panel__mine-fix">{{ row.fix }}</span>
					<span class="ocrflow-panel__mine-meta">
						{{ row.label }}<template v-if="row.age !== null"> · {{ age(row.age) }}</template><template v-if="row.attempts !== null"> · {{ t('{count} attempts', { count: row.attempts }) }}</template>
					</span>
				</div>
				<NcButton v-if="row.retryable" :disabled="mineBusy" @click="retry(row)">
					{{ retrying === rowKey(row) ? t('Retrying…') : t('Retry') }}
				</NcButton>
			</li>
		</ul>
		<p v-else class="ocrflow-panel__empty">
			{{ t('Nothing of yours needs attention.') }}
		</p>
		<p v-if="mineMessage" class="ocrflow-panel__result">
			{{ mineMessage }}
		</p>

		<h3 class="ocrflow-panel__heading">
			{{ t('Queue') }}
		</h3>
		<ul v-if="rows.length" class="ocrflow-panel__counts">
			<li v-for="row in rows" :key="row.status" :class="{ 'is-error': row.status === 'error' }">
				<span class="ocrflow-panel__status">{{ row.status }}</span>
				<span class="ocrflow-panel__count">{{ row.count }}</span>
			</li>
		</ul>
		<p v-else class="ocrflow-panel__empty">
			{{ t('Nothing has been queued yet.') }}
		</p>

		<h3 class="ocrflow-panel__heading">
			{{ t('Scan a folder') }}
		</h3>
		<form class="ocrflow-panel__form" @submit.prevent="scan">
			<NcTextField
				v-model="folder"
				:label="t('Folder path, relative to your files')"
				placeholder="Scans/2026"
				:disabled="busy" />
			<NcButton type="primary" :disabled="busy || folder.trim() === ''" @click="scan">
				{{ busy ? t('Queueing…') : t('Scan') }}
			</NcButton>
		</form>
		<p v-if="lastScan" class="ocrflow-panel__result">
			{{ lastScan }}
		</p>

		<h3 class="ocrflow-panel__heading">
			{{ t('Automatic OCR') }}
		</h3>
		<p class="ocrflow-panel__intro">
			{{ t('Uploads are processed automatically. Turn this off if you do not want your files sent for OCR.') }}
		</p>
		<NcCheckboxRadioSwitch
			type="switch"
			:checked="autoOcrEnabled"
			:disabled="busy"
			@update:checked="setAutoOcrEnabled">
			{{ t('Process my uploads automatically') }}
		</NcCheckboxRadioSwitch>

		<div class="ocrflow-panel__actions">
			<NcButton :disabled="busy" @click="refresh">
				{{ t('Refresh') }}
			</NcButton>
			<span v-if="updatedAt" class="ocrflow-panel__updated">
				{{ t('Updated {time}', { time: updatedAt }) }}
			</span>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t as translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const BASE = '/ocs/v2.php/apps/ocrflow/api/v1'

export default {
	name: 'OcrFlowPanel',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField },
	data() {
		return {
			intro: 'Files you upload are queued automatically. Counts below are for this instance, not this folder.',
			counts: {},
			folder: '',
			busy: false,
			error: '',
			lastScan: '',
			updatedAt: '',
			engine: '',
			engineConfigured: true,
			autoOcrEnabled: true,
			// The caller's own rows — separate state from the instance-wide
			// counts above, because "3 errors on this instance" and "2 of them
			// are mine" are different answers and must not be confused.
			mineRows: [],
			mineLoaded: false,
			mineError: '',
			mineBusy: false,
			mineMessage: '',
			retrying: '',
		}
	},
	computed: {
		// Whatever the queue reports, rendered as-is: hardcoding the status list
		// here would silently drop a new state ("claimed" was added that way).
		rows() {
			return Object.entries(this.counts ?? {}).map(([status, count]) => ({ status, count }))
		},
	},
	mounted() {
		this.refresh()
		this.loadMe()
		this.loadMine()
	},
	methods: {
		t(text, params) {
			// The real translation function, not a passthrough: a passthrough
			// makes the whole interface English in every locale, silently.
			return translate('ocrflow', text, params)
		},
		rowKey(row) {
			// Origins have separate id spaces: a job id and a ledger row id
			// can be the same number for different files.
			return `${row.origin}-${row.id}`
		},
		shortPath(path) {
			// The panel lives inside the user's own settings, so the
			// `/alice/files/` prefix is noise. Show what is left; keep the
			// full path in the title attribute for the ambiguous cases.
			const marker = '/files/'
			const i = typeof path === 'string' ? path.indexOf(marker) : -1
			return i === -1 ? path : path.slice(i + marker.length)
		},
		age(seconds) {
			if (seconds < 60) {
				return this.t('just now')
			}
			const minutes = Math.floor(seconds / 60)
			if (minutes < 60) {
				return this.t('{count} min ago', { count: minutes })
			}
			const hours = Math.floor(minutes / 60)
			if (hours < 24) {
				return this.t('{count} h ago', { count: hours })
			}
			return this.t('{count} d ago', { count: Math.floor(hours / 24) })
		},
		async loadMine() {
			this.mineBusy = true
			this.mineError = ''
			try {
				const { data } = await axios.get(`${BASE}/mine`, { headers: { 'OCS-APIRequest': 'true' } })
				const rows = data?.ocs?.data?.rows
				// A malformed answer must not render as "nothing needs
				// attention" — that would be a lie in the shape of a clean
				// bill of health.
				if (!Array.isArray(rows)) {
					throw new Error('unexpected answer')
				}
				this.mineRows = rows
				this.mineLoaded = true
			} catch (e) {
				// Keep the last known rows out of sight: a stale row next to
				// an error reads like it is still true.
				this.mineRows = []
				this.mineMessage = ''
				this.mineError = e?.response?.status ?? e?.message ?? 'unknown error'
			} finally {
				this.mineBusy = false
			}
		},
		async retry(row) {
			this.retrying = this.rowKey(row)
			this.mineError = ''
			this.mineMessage = ''
			try {
				const { data } = await axios.post(
					`${BASE}/mine/retry`,
					{ ids: [row.id] },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				const result = data?.ocs?.data ?? {}
				// The server reports refusals; say so rather than showing a
				// cheerful "done" for a retry that never ran.
				this.mineMessage = (result.retried ?? 0) > 0
					? this.t('Queued again — the next OCR run picks it up.')
					: this.t('Not queued: this row is not yours to retry, or it is no longer waiting.')
				await this.loadMine()
			} catch (e) {
				this.mineError = e?.response?.status ?? e?.message ?? 'unknown error'
			} finally {
				this.retrying = ''
			}
		},
		async refresh() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.get(`${BASE}/status`, { headers: { 'OCS-APIRequest': 'true' } })
				this.counts = data?.ocs?.data?.queue ?? {}
				this.updatedAt = new Date().toLocaleTimeString()
			} catch (e) {
				this.error = `Could not read the queue: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.busy = false
			}
		},
		async loadMe() {
			try {
				const { data } = await axios.get(`${BASE}/me`, { headers: { 'OCS-APIRequest': 'true' } })
				const me = data?.ocs?.data ?? {}
				this.autoOcrEnabled = me.auto_ocr_enabled !== false
				this.engine = me.engine ?? ''
				this.engineConfigured = me.engine_configured !== false
			} catch (e) {
				// Not being able to read the state must not hide the queue: the
				// panel still works, it just cannot say whether OCR is on.
				this.engineConfigured = true
			}
		},
		async setAutoOcrEnabled(value) {
			// One-way binding: the switch shows what the server last confirmed.
			// Inverting on failure would show the opposite of the truth — the
			// state is the server's answer, not the click.
			this.busy = true
			this.error = ''
			try {
				await axios.put(
					`${BASE}/me`,
					{ auto_ocr_enabled: value === true },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				this.autoOcrEnabled = value === true
			} catch (e) {
				this.error = `Could not save your choice: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.busy = false
			}
		},
		async scan() {
			const path = this.folder.trim()
			if (path === '') {
				return
			}
			this.busy = true
			this.error = ''
			this.lastScan = ''
			try {
				const { data } = await axios.post(
					`${BASE}/scan`,
					{ path },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				this.lastScan = data?.ocs?.data?.queued
					? `Scan queued for /${path} — progress appears in the counts above.`
					: 'The scan request was accepted.'
				await this.refresh()
			} catch (e) {
				this.error = `Could not queue the scan: ${e?.response?.status ?? e?.message ?? 'unknown error'}`
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.ocrflow-panel {
	max-width: 40rem;
}

/* Nextcloud's app-navigation toggle is `position: fixed` at top:50px, 44x44
   at the inline start (core/css/server.css), so without top padding the first
   line of the panel sits underneath it. Reserve its height when narrow. */
@media (max-width: 1024px) {
	.ocrflow-panel {
		padding-top: calc(var(--default-grid-baseline, 4px) * 12);
	}
}

.ocrflow-panel__intro,
.ocrflow-panel__empty,
.ocrflow-panel__updated {
	color: var(--color-text-maxcontrast);
}

.ocrflow-panel__heading {
	margin-top: calc(var(--default-grid-baseline, 4px) * 6);
}

.ocrflow-panel__counts {
	list-style: none;
	padding: 0;
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
}

.ocrflow-panel__counts li {
	display: flex;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
	/* Full width, not a 16rem island: the counts should line up with the
	   controls below them instead of stopping two thirds across the panel. */
	width: 100%;
	padding: var(--default-grid-baseline, 4px) 0;
	border-bottom: 1px solid var(--color-border);
}

.ocrflow-panel__status {
	/* Presentation only: the machine state stays exactly as the queue reports
	   it ("done", "error", "skipped", and whatever comes next); the reader gets
	   a capital first letter. */
	text-transform: capitalize;
}

.ocrflow-panel__count {
	font-variant-numeric: tabular-nums;
	font-weight: bold;
}

/* The one state that needs colour: failures. Nextcloud's status tokens carry
   the light/dark theme switch, so no hex lives here. */
.ocrflow-panel__counts li.is-error .ocrflow-panel__count {
	color: var(--color-error);
}

.ocrflow-panel__form {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: flex-end;
	flex-wrap: wrap;
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

/* On a phone the input and the button cannot share a row: the button shrinks
   until its label truncates to "S…". Stack them instead — one full-width field,
   one full-width action. */
@media (max-width: 600px) {
	.ocrflow-panel__form {
		flex-direction: column;
		align-items: stretch;
	}
}

.ocrflow-panel__actions {
	display: flex;
	gap: 0.75rem;
	align-items: center;
	margin-top: 1.5rem;
}

.ocrflow-panel__error {
	margin-bottom: 1rem;
}

/* Your own rows: path first (it is what the user recognises), then the fix
   hint, then the provenance. One row per line; the Retry button stays on the
   inline end and never shrinks the text to nothing. */
.ocrflow-panel__mine {
	list-style: none;
	padding: 0;
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
}

.ocrflow-panel__mine-row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
	padding: calc(var(--default-grid-baseline, 4px) * 3) 0;
	border-bottom: 1px solid var(--color-border);
}

/* Flexible text column: it must be allowed to shrink (min-width: 0) so the
   path ellipsizes, and the button must never be the thing that shrinks — a
   squeezed NcButton truncates its label to "Re…", which is unreadable. */
.ocrflow-panel__mine-text {
	display: flex;
	min-width: 0;
	flex: 1 1 auto;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.ocrflow-panel__mine-row :deep(.button-vue) {
	flex: 0 0 auto;
	white-space: nowrap;
}

/* On a phone the row cannot hold text and a button side by side: stack them,
   with the action full width so its label always fits. */
@media (max-width: 600px) {
	.ocrflow-panel__mine-row {
		flex-direction: column;
		align-items: stretch;
		gap: calc(var(--default-grid-baseline, 4px) * 2);
	}

	.ocrflow-panel__mine-row :deep(.button-vue) {
		width: 100%;
	}
}

.ocrflow-panel__mine-path {
	overflow: hidden;
	font-weight: bold;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ocrflow-panel__mine-fix {
	overflow-wrap: anywhere;
}

.ocrflow-panel__mine-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}
</style>
