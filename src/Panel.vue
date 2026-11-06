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
			{{ t('Queue') }}
		</h3>
		<ul v-if="rows.length" class="ocrflow-panel__counts">
			<li v-for="row in rows" :key="row.status">
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
			:checked="autoOcrDisabled"
			:disabled="busy"
			@update:checked="setOptOut">
			{{ t('Do not process my uploads automatically') }}
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
			autoOcrDisabled: false,
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
	},
	methods: {
		t(text, params) {
			// The real translation function, not a passthrough: a passthrough
			// makes the whole interface English in every locale, silently.
			return translate(text, params)
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
				this.autoOcrDisabled = me.auto_ocr_disabled === true
				this.engine = me.engine ?? ''
				this.engineConfigured = me.engine_configured !== false
			} catch (e) {
				// Not being able to read the state must not hide the queue: the
				// panel still works, it just cannot say whether OCR is on.
				this.engineConfigured = true
			}
		},
		async setOptOut(value) {
			// One-way binding: the switch shows what the server last confirmed.
			// Inverting on failure would show the opposite of the truth — the
			// state is the server's answer, not the click.
			this.busy = true
			this.error = ''
			try {
				await axios.put(
					`${BASE}/me`,
					{ auto_ocr_disabled: value === true },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				this.autoOcrDisabled = value === true
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

.ocrflow-panel__intro,
.ocrflow-panel__empty,
.ocrflow-panel__updated {
	color: var(--color-text-maxcontrast);
}

.ocrflow-panel__heading {
	margin-top: 1.5rem;
}

.ocrflow-panel__counts {
	list-style: none;
	padding: 0;
	margin: 0.5rem 0 0;
}

.ocrflow-panel__counts li {
	display: flex;
	justify-content: space-between;
	max-width: 16rem;
	padding: 0.25rem 0;
	border-bottom: 1px solid var(--color-border);
}

.ocrflow-panel__count {
	font-variant-numeric: tabular-nums;
	font-weight: bold;
}

.ocrflow-panel__form {
	display: flex;
	gap: 0.5rem;
	align-items: flex-end;
	margin-top: 0.5rem;
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
</style>
