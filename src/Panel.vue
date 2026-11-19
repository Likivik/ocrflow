<template>
	<div class="ocrflow-panel">

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

				<!-- Collapsed by default: the worklist can be hundreds of rows, and a
		     person opening their settings is usually here for the queue
		     numbers, not to scroll past every file. The count is the server's
		     own total, not the length of the page we happen to hold. -->
		<h3 class="ocrflow-panel__heading">
			<button
				type="button"
				class="ocrflow-panel__heading--toggle"
				aria-controls="ocrflow-panel-attention"
				:aria-expanded="attentionOpen ? 'true' : 'false'"
				@click="attentionOpen = !attentionOpen">
				<span class="ocrflow-panel__heading-text">{{ t('Your files that need attention') }}</span>
				<span v-if="mineLoaded" class="ocrflow-panel__heading-count">{{ mineTotal }}</span>
				<span class="ocrflow-panel__chevron" :class="{ 'is-open': attentionOpen }" aria-hidden="true" />
			</button>
		</h3>
		<!-- A failed load is not something to hide behind a collapsed section. -->
		<NcNoteCard v-if="mineError" type="error">
			{{ t('Could not read your files: {reason}', { reason: mineError }) }}
		</NcNoteCard>
		<div v-if="attentionOpen" id="ocrflow-panel-attention" class="ocrflow-panel__attention">
			<p v-if="!mineLoaded" class="ocrflow-panel__empty">
				{{ t('Reading your files…') }}
			</p>
			<!-- Two different nothings: an empty worklist is good news, an empty
			     filter is not the same answer and must not be dressed as one. -->
			<p v-else-if="!mineRows.length" class="ocrflow-panel__empty">
				{{ t('Nothing of yours needs attention.') }}
			</p>
			<template v-else>
				<div class="ocrflow-panel__filters">
					<div class="ocrflow-panel__chips" role="group" :aria-label="t('Filter by problem')">
						<NcButton
							v-for="chip in filterChips"
							:key="chip.id"
							:pressed="activeFilter === chip.id"
							variant="tertiary"
							@click="activeFilter = chip.id">
							{{ chip.label }} ({{ chip.count }})
						</NcButton>
					</div>
					<NcTextField
						v-model="pathQuery"
						class="ocrflow-panel__search"
						:label="t('Search by path')"
						placeholder="Scans/2026" />
				</div>

				<div class="ocrflow-panel__list-head">
					<NcCheckboxRadioSwitch
						type="checkbox"
						:checked="allVisibleSelected"
						@update:checked="toggleAllVisible">
						{{ t('Select all shown ({count})', { count: visibleRows.length }) }}
					</NcCheckboxRadioSwitch>
					<span class="ocrflow-panel__list-count">
						{{ t('{shown} of {total} shown', { shown: visibleRows.length, total: mineRows.length }) }}
					</span>
				</div>

				<!-- The tabs and the select-all bar are the controls for the list,
				     so they stay put; the rows scroll under them. -->
				<div class="ocrflow-panel__mine-scroll">
				<p v-if="!visibleRows.length" class="ocrflow-panel__empty">
					{{ t('No files match this filter.') }}
					<NcButton variant="tertiary" @click="clearFilters">
						{{ t('Show all') }}
					</NcButton>
				</p>
				<ul v-else class="ocrflow-panel__mine">
					<li
						v-for="row in visibleRows"
						:key="rowKey(row)"
						class="ocrflow-panel__mine-row"
						:class="{ 'is-selected': isSelected(row) }">
						<NcCheckboxRadioSwitch
							type="checkbox"
							:checked="isSelected(row)"
							:aria-label="t('Select {path}', { path: shortPath(row.path) })"
							@update:checked="(checked) => setSelected(row, checked)" />
						<div class="ocrflow-panel__mine-text">
							<span class="ocrflow-panel__mine-dir">{{ pathParts(row.path).dir }}</span>
							<span class="ocrflow-panel__mine-name" :title="row.path">{{ pathParts(row.path).name }}</span>
							<span class="ocrflow-panel__mine-text">{{ row.text }}</span>
							<span v-if="row.error" class="ocrflow-panel__mine-error">{{ row.error }}</span>
							<span class="ocrflow-panel__mine-meta">
								<template v-if="row.age !== null">{{ age(row.age) }}</template><template v-if="row.attempts !== null"> · {{ t('{count} attempts', { count: row.attempts }) }}</template>
							</span>
						</div>
						<div class="ocrflow-panel__mine-actions">
							<!-- Open is a plain link: the file is Nextcloud's, not
							     ours, and the viewer is the right place to look at it. -->
							<NcButton
								v-if="row.file_id"
								:href="fileUrl(row)"
								target="_blank"
								rel="noopener">
								{{ t('Open') }}
							</NcButton>
							<!-- Retry only where a second attempt can change the
							     answer; a stamp needs a decision, not another run. -->
							<NcButton v-if="row.retryable" :disabled="mineBusy" @click="retry(row)">
								{{ retrying === rowKey(row) ? t('Retrying…') : t('Retry') }}
							</NcButton>
							<NcButton variant="error" :disabled="mineBusy" @click="askDeleteRow(row)">
								{{ t('Delete') }}
							</NcButton>
						</div>
					</li>
				</ul>
				</div>

				<!-- Sticky, because the selection is about rows that may have
				     scrolled away: the bar says how many and what will happen. -->
				<div v-if="selectedCount" class="ocrflow-panel__bulk">
					<span class="ocrflow-panel__bulk-count">
						{{ t('{count} selected', { count: selectedCount }) }}
					</span>
					<NcButton
						v-if="selectedRetryableCount"
						:disabled="mineBusy"
						@click="bulkRetry">
						{{ t('Retry {count}', { count: selectedRetryableCount }) }}
					</NcButton>
					<NcButton variant="error" :disabled="mineBusy" @click="askDeleteSelected">
						{{ t('Delete {count}', { count: selectedCount }) }}
					</NcButton>
				</div>
			</template>
		</div>
		<p v-if="mineMessage" class="ocrflow-panel__result">
			{{ mineMessage }}
		</p>

		<NcDialog
			v-if="deleteTarget"
			:name="t('Move to trash?')"
			:message="deleteMessage"
			:buttons="deleteButtons"
			@closing="deleteTarget = null" />

		<h3 class="ocrflow-panel__heading">
			{{ t('Queue') }}
		</h3>
		<!-- The report: six blocks, each with the rows that add up to it.
		     Everything comes from the server as-is — the panel never decides what
		     a number means. -->
		<section
			v-for="block in blocks"
			:key="block.id"
			:class="['ocrflow-panel__block', block.id === 'error' ? 'ocrflow-panel__block--error' : '']">
			<h4 class="ocrflow-panel__block-heading">
				{{ t(block.label) }}
				<span class="ocrflow-panel__count">{{ block.count }}</span>
			</h4>
			<ul v-if="block.rows.length" class="ocrflow-panel__counts">
				<li
					v-for="row in block.rows"
					:key="block.id + '-' + row.key"
					:class="{ 'is-below-bar': row.below_bar }"
					:title="row.title || row.key">
					<span class="ocrflow-panel__status">{{ t(row.label) }}</span>
					<span class="ocrflow-panel__count">{{ row.count }}</span>
				</li>
			</ul>
			<!-- The one block a person can act on in bulk. Everything else is
			     either already done or needs a single file's attention. -->
			<template v-if="block.id === 'not_processed'">
				<p v-if="block.count === 0" class="ocrflow-panel__hint">
					{{ t('Nothing left to process.') }}
				</p>
				<form v-else class="ocrflow-panel__process" @submit.prevent="processAll">
					<NcTextField
						v-model="batchSize"
						class="ocrflow-panel__batch"
						type="number"
						:label="t('Files to process')"
						:min="1"
						:max="processInfo.max_files"
						:disabled="processing"
						@update:model-value="rememberBatchSize" />
					<NcButton
						type="primary"
						:disabled="processDisabled"
						:title="processReason"
						@click="processAll">
						{{ processing ? t('Queueing…') : processLabel }}
					</NcButton>
				</form>
				<p v-if="block.count > 0" class="ocrflow-panel__hint">
					{{ processEstimate }}
				</p>
				<p v-if="processMessage" class="ocrflow-panel__hint">
					{{ processMessage }}
				</p>
			</template>
		</section>


		<p v-if="!blocks.length" class="ocrflow-panel__empty">
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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const BASE = '/ocs/v2.php/apps/ocrflow/api/v1'

// The filter chips are a READING of the reason_code, not a second source of
// truth: every bucket below names codes the server already decided on, so a
// file cannot appear under "Damaged" here and under something else in the
// admin worklist. A code nobody mapped falls into "Failed" only if it is an
// error, and into nothing at all otherwise.
const BUCKETS = [
	{ id: 'all', label: 'All' },
	{ id: 'damaged', label: 'Damaged' },
	{ id: 'empty', label: 'Empty' },
	{ id: 'failed', label: 'Failed' },
	{ id: 'decide', label: 'To decide' },
]

export default {
	name: 'OcrFlowPanel',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcTextField },
	data() {
		return {
			counts: {},
			blocks: [],
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
			// The button's numbers, straight from the report: what it may take,
			// what is left of the day, and what this account's history says.
			processInfo: {
				batch_size: 25,
				max_files: 200,
				daily_used: 0,
				daily_cap: 200,
				estimate: { avg_pages: null, samples: 0, per_minute: null },
			},
			batchSize: 25,
			processing: false,
			processMessage: '',
			batchSaveTimer: null,
			batchLoaded: false,
			mineRows: [],
			// The server's total, because mineRows is one capped page.
			mineTotal: 0,
			mineLoaded: false,
			mineError: '',
			mineBusy: false,
			mineMessage: '',
			retrying: '',
			// Filters are client-side over the loaded rows: the endpoint is a
			// worklist, not a query engine, and a chip that re-queries would
			// make a 200-row page feel like a search box.
			// Collapsed by default; the heading still shows the count.
			attentionOpen: false,
			activeFilter: 'all',
			pathQuery: '',
			// Selection is by row key (origin + id), because a job id and a
			// ledger row id share a number space and must not alias.
			selected: [],
			// The rows awaiting the trash confirmation, or null.
			deleteTarget: null,
		}
	},
	computed: {
		// Whatever the queue reports, rendered as-is: hardcoding the status list
		// here would silently drop a new state ("claimed" was added that way).
		filterChips() {
			return BUCKETS.map((bucket) => ({
				...bucket,
				label: this.t(bucket.label),
				count: bucket.id === 'all'
					? this.mineRows.length
					: this.mineRows.filter((row) => this.bucketFor(row) === bucket.id).length,
			}))
		},
		visibleRows() {
			const query = this.pathQuery.trim().toLowerCase()
			return this.mineRows.filter((row) => {
				if (this.activeFilter !== 'all' && this.bucketFor(row) !== this.activeFilter) {
					return false
				}
				if (query === '') {
					return true
				}
				return `${row.path} ${this.shortPath(row.path)}`.toLowerCase().includes(query)
			})
		},
		selectedRows() {
			return this.mineRows.filter((row) => this.selected.includes(this.rowKey(row)))
		},
		selectedCount() {
			return this.selectedRows.length
		},
		selectedRetryableCount() {
			return this.selectedRows.filter((row) => row.retryable).length
		},
		allVisibleSelected() {
			return this.visibleRows.length > 0
				&& this.visibleRows.every((row) => this.selected.includes(this.rowKey(row)))
		},
		deleteMessage() {
			const rows = this.deleteTarget ?? []
			if (rows.length === 1) {
				return this.t('“{name}” moves to your trash. You can restore it for about 30 days.', { name: this.shortPath(rows[0].path) })
			}
			return this.t('{count} files move to your trash. You can restore them for about 30 days.', { count: rows.length })
		},
		deleteButtons() {
			return [
				{
					label: this.t('Cancel'),
					callback: () => {
						this.deleteTarget = null
					},
				},
				{
					label: this.t('Move to trash'),
					variant: 'error',
					callback: () => {
						this.confirmDelete()
					},
				},
			]
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
		pathParts(path) {
			// The worklist row shows where the file lives and what it is
			// called, as two pieces rather than one truncated string: the
			// directory is long and generic, the file name is what the reader
			// is looking for, and neither may be cut off. The `/alice/files/`
			// prefix is noise inside the user's own settings, so it is dropped.
			const marker = '/files/'
			const raw = typeof path === 'string' ? path : ''
			const i = raw.indexOf(marker)
			const shown = i === -1 ? raw : raw.slice(i + marker.length)
			const cut = shown.lastIndexOf('/')
			if (cut === -1) {
				return { dir: '', name: shown }
			}
			return { dir: shown.slice(0, cut + 1), name: shown.slice(cut + 1) }
		},
		shortPath(path) {
			// The panel lives inside the user's own settings, so the
			// `/alice/files/` prefix is noise. Show what is left; keep the
			// full path in the title attribute for the ambiguous cases.
			const marker = '/files/'
			const i = typeof path === 'string' ? path.indexOf(marker) : -1
			return i === -1 ? path : path.slice(i + marker.length)
		},
		fileUrl(row) {
			// Nextcloud's own file route. The viewer decides how to open it;
			// we only know which file.
			return `/f/${row.file_id}`
		},
		bucketFor(row) {
			const code = row.code ?? ''
			if (code === 'error_file_broken') {
				return 'damaged'
			}
			if (code === 'error_source_file_zero_bytes') {
				return 'empty'
			}
			if (row.kind === 'redo') {
				return 'decide'
			}
			if (code.startsWith('error_')) {
				return 'failed'
			}
			return 'other'
		},
		clearFilters() {
			this.activeFilter = 'all'
			this.pathQuery = ''
		},
		isSelected(row) {
			return this.selected.includes(this.rowKey(row))
		},
		setSelected(row, checked) {
			const key = this.rowKey(row)
			if (checked === true && !this.selected.includes(key)) {
				this.selected = [...this.selected, key]
			}
			if (checked !== true) {
				this.selected = this.selected.filter((k) => k !== key)
			}
		},
		toggleAllVisible(checked) {
			const keys = this.visibleRows.map((row) => this.rowKey(row))
			if (checked === true) {
				this.selected = Array.from(new Set([...this.selected, ...keys]))
			} else {
				this.selected = this.selected.filter((key) => !keys.includes(key))
			}
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
				const total = Number(data?.ocs?.data?.total)
				this.mineRows = rows
				this.mineTotal = Number.isFinite(total) ? total : rows.length
				// A selection that survived a reload would point at rows the
				// user can no longer see.
				const keys = new Set(rows.map((row) => this.rowKey(row)))
				this.selected = this.selected.filter((key) => keys.has(key))
				this.mineLoaded = true
			} catch (e) {
				// Keep the last known rows out of sight: a stale row next to
				// an error reads like it is still true.
				this.mineRows = []
				this.selected = []
				this.mineMessage = ''
				this.mineError = e?.response?.status ?? e?.message ?? 'unknown error'
			} finally {
				this.mineBusy = false
			}
		},
		async retry(row) {
			this.retrying = this.rowKey(row)
			await this.requestRetry([row.id])
			this.retrying = ''
		},
		async bulkRetry() {
			const ids = this.selectedRows.filter((row) => row.retryable).map((row) => row.id)
			if (!ids.length) {
				return
			}
			await this.requestRetry(ids)
		},
		async requestRetry(ids) {
			this.mineError = ''
			this.mineMessage = ''
			try {
				const { data } = await axios.post(
					`${BASE}/mine/retry`,
					{ ids },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				const result = data?.ocs?.data ?? {}
				const retried = result.retried ?? 0
				const refused = result.refused ?? 0
				// The server reports refusals; say so rather than showing a
				// cheerful "done" for a retry that never ran.
				if (retried === 0) {
					this.mineMessage = this.t('Not queued: these rows are not yours to retry, or they are no longer waiting.')
				} else if (refused > 0) {
					this.mineMessage = this.t('{retried} queued again, {refused} left alone.', { retried, refused })
				} else {
					this.mineMessage = this.t('Queued for another attempt.')
				}
				await this.loadMine()
			} catch (e) {
				this.mineError = e?.response?.status ?? e?.message ?? 'unknown error'
			}
		},
		askDeleteRow(row) {
			this.mineError = ''
			this.mineMessage = ''
			this.deleteTarget = [row]
		},
		askDeleteSelected() {
			this.mineError = ''
			this.mineMessage = ''
			this.deleteTarget = [...this.selectedRows]
		},
		async confirmDelete() {
			const rows = this.deleteTarget ?? []
			this.deleteTarget = null
			// A row with no file id is a listing defect, not a file to delete.
			const ids = rows
				.map((row) => Number(row.file_id))
				.filter((id) => Number.isInteger(id) && id > 0)
			if (!ids.length) {
				this.mineError = this.t('These rows do not name a file, so nothing was deleted.')
				return
			}
			this.mineBusy = true
			this.mineError = ''
			this.mineMessage = ''
			try {
				if (ids.length === 1) {
					// One row: the single-file route, so a refusal and a
					// vanished file get their own answers (403 / 404).
					try {
						await axios.delete(`${BASE}/mine/${ids[0]}`, { headers: { 'OCS-APIRequest': 'true' } })
						this.mineMessage = this.t('Moved to your trash. You can restore it for about 30 days.')
					} catch (e) {
						if (e?.response?.status === 404) {
							this.mineMessage = this.t('That file was already gone.')
						} else {
							throw e
						}
					}
				} else {
					// A selection: ONE request, and the answer says what
					// happened to every id instead of a cheerful total.
					const { data } = await axios.delete(`${BASE}/mine`, {
						data: { ids },
						headers: { 'OCS-APIRequest': 'true' },
					})
					const result = data?.ocs?.data ?? {}
					const deleted = result.deleted ?? 0
					const refused = result.refused ?? 0
					if (refused > 0) {
						this.mineMessage = this.t('{deleted} moved to trash, {refused} left alone.', { deleted, refused })
					} else {
						this.mineMessage = this.t('{count} moved to your trash. You can restore them for about 30 days.', { count: deleted })
					}
				}
				this.selected = []
				await this.loadMine()
			} catch (e) {
				this.mineError = e?.response?.status ?? e?.message ?? 'unknown error'
			} finally {
				this.mineBusy = false
			}
		},
		processCount() {
			const block = this.blocks.find((b) => b.id === 'not_processed')
			return block ? Number(block.count) : 0
		},
		// The effective number: a field that says 25 when three are left is a
		// label that lies about what the click will do.
		processEffective() {
			return Math.max(0, Math.min(Number(this.batchSize) || 0, this.processCount))
		},
		processLabel() {
			const n = this.processEffective
			return n === 1 ? this.t('Process 1 file') : this.t('Process {count} files', { count: n })
		},
		processDisabled() {
			return this.processing
				|| !this.engineConfigured
				|| this.processEffective < 1
				|| this.processInfo.daily_used >= this.processInfo.daily_cap
		},
		processReason() {
			if (!this.engineConfigured) {
				return this.t('No OCR engine is configured')
			}
			if (this.processInfo.daily_used >= this.processInfo.daily_cap) {
				return this.t('Daily limit reached: {count} files.', { count: this.processInfo.daily_cap })
			}
			return ''
		},
		processEstimate() {
			const estimate = this.processInfo.estimate || {}
			const n = this.processEffective
			if (estimate.avg_pages === null || estimate.avg_pages === undefined) {
				return this.t('No page estimate yet.')
			}
			const pages = Math.max(1, Math.round(estimate.avg_pages * n))
			let text = this.t('About {pages} pages', { pages })
			if (estimate.per_minute) {
				const minutes = Math.max(1, Math.round(n / estimate.per_minute))
				text += ' · ' + this.t('about {minutes} min', { minutes })
			}
			return text
		},
		async processAll() {
			const limit = this.processEffective
			if (limit < 1 || this.processing) {
				return
			}
			this.processing = true
			this.processMessage = ''
			try {
				const { data } = await axios.post(
					`${BASE}/process`,
					{ limit },
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				const result = data?.ocs?.data ?? {}
				this.processMessage = this.t(
					'{queued} queued, {remaining} left to process.',
					{ queued: result.queued ?? 0, remaining: result.remaining ?? 0 },
				)
				if ((result.refused ?? 0) > 0) {
					this.processMessage += ' ' + this.t('{count} could not be queued.', { count: result.refused })
				}
				if ((result.skipped_young ?? 0) > 0) {
					this.processMessage += ' ' + this.t('{count} still settling.', { count: result.skipped_young })
				}
				await this.refresh()
			} catch (e) {
				const status = e?.response?.status
				if (status === 409) {
					this.processMessage = this.t('No OCR engine is configured')
				} else if (status === 429) {
					this.processMessage = this.t('Daily limit reached: {count} files.', {
						count: e?.response?.data?.ocs?.data?.message ?? this.processInfo.daily_cap,
					})
				} else if (status === 403) {
					this.processMessage = this.t('OCR is not enabled for your account.')
				} else {
					this.processMessage = this.t('Could not queue: {reason}', {
						reason: status ?? e?.message ?? 'unknown error',
					})
				}
			} finally {
				this.processing = false
			}
		},
		/**
		 * Save the field on change, debounced. Blur would lose the value: the
		 * field is disabled while a run is in flight, and a disabled input
		 * fires no blur.
		 */
		rememberBatchSize() {
			clearTimeout(this.batchSaveTimer)
			this.batchSaveTimer = setTimeout(async () => {
				try {
					await axios.post(
						`${BASE}/process/limit`,
						{ size: Number(this.batchSize) },
						{ headers: { 'OCS-APIRequest': 'true' } },
					)
				} catch (e) {
					// A preference that did not save is not worth an error banner.
				}
			}, 600)
		},
		async refresh() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.get(`${BASE}/status`, { headers: { 'OCS-APIRequest': 'true' } })
				this.counts = data?.ocs?.data?.queue ?? {}
				// The reasons behind the Skipped total, each with the sentence
				// the app already writes for its code. Server-side so the
				// reader here stays dumb, and so a new reason needs no release.
				this.blocks = data?.ocs?.data?.blocks ?? []
				const process = data?.ocs?.data?.process
				if (process) {
					this.processInfo = { ...this.processInfo, ...process }
					// Only adopt the stored preference on the first read: a
					// reload while someone is typing must not undo the typing.
					if (!this.batchLoaded) {
						this.batchSize = process.batch_size
						this.batchLoaded = true
					}
				}
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

/* The heading is a button now: the section is collapsed by default, and this is
   the only thing to click. It must look like a heading, not like a control. */
.ocrflow-panel__heading--toggle {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	width: 100%;
	padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
	background: none;
	border: 0;
	border-bottom: 1px solid var(--color-border);
	color: inherit;
	font: inherit;
	font-weight: bold;
	text-align: start;
	cursor: pointer;
}

.ocrflow-panel__heading--toggle:hover,
.ocrflow-panel__heading--toggle:focus-visible {
	color: var(--color-primary-element);
}

.ocrflow-panel__heading--toggle:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.ocrflow-panel__heading-count {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.ocrflow-panel__chevron {
	margin-inline-start: auto;
	width: 8px;
	height: 8px;
	border-right: 2px solid currentColor;
	border-bottom: 2px solid currentColor;
	transform: rotate(45deg);
	transition: transform 120ms ease-in-out;
}

.ocrflow-panel__chevron.is-open {
	transform: rotate(-135deg);
}

.ocrflow-panel__attention {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

/* The rows scroll, the controls above them do not: tabs and "select all shown"
   are how the list is read, so they stay where the eye already is. */
.ocrflow-panel__mine-scroll {
	max-height: 22rem;
	overflow-y: auto;
	overscroll-behavior: contain;
	padding-inline-end: calc(var(--default-grid-baseline, 4px) * 1);
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

/* Filters: chips wrap, the search takes the rest of the line. Both are
   controls for the list below, so they sit above it and above the header. */
.ocrflow-panel__filters {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	align-items: flex-end;
	margin-top: calc(var(--default-grid-baseline, 4px) * 3);
}

.ocrflow-panel__chips {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 4px);
}

/* A chip must not shrink its label to "...": it wraps onto its own line
   instead, which is what the flex-wrap above is for. */
.ocrflow-panel__chips :deep(.button-vue) {
	flex: 0 0 auto;
	white-space: nowrap;
}

.ocrflow-panel__search {
	flex: 1 1 12rem;
	min-width: 0;
}

.ocrflow-panel__list-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	flex-wrap: wrap;
	margin-top: calc(var(--default-grid-baseline, 4px) * 3);
}

.ocrflow-panel__list-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-variant-numeric: tabular-nums;
}

/* Your own rows: path first (it is what the user recognises), then the fix
   hint, then the provenance. The actions stay on the inline end and never
   shrink the text to nothing. */
.ocrflow-panel__mine {
	list-style: none;
	padding: 0;
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
}

.ocrflow-panel__mine-row {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 3) 0;
	border-bottom: 1px solid var(--color-border);
}

.ocrflow-panel__mine-row.is-selected {
	background-color: var(--color-background-hover);
}

/* Flexible text column: it must be allowed to shrink (min-width: 0) so the
   path ellipsizes, and the buttons must never be the thing that shrinks — a
   squeezed NcButton truncates its label to "Re…", which is unreadable. */
.ocrflow-panel__mine-text {
	display: flex;
	min-width: 0;
	flex: 1 1 auto;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.ocrflow-panel__mine-actions {
	display: flex;
	flex: 0 0 auto;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: flex-start;
}

.ocrflow-panel__mine-row :deep(.button-vue) {
	flex: 0 0 auto;
	white-space: nowrap;
}

/* On a phone a row cannot hold text and three actions side by side: stack
   them, with the actions wrapping rather than truncating. */
@media (max-width: 600px) {
	.ocrflow-panel__mine-row {
		flex-direction: column;
		align-items: stretch;
		gap: calc(var(--default-grid-baseline, 4px) * 2);
	}

	.ocrflow-panel__mine-actions {
		flex-wrap: wrap;
	}
}

/* The selection bar follows the scroll: the rows it acts on may be above or
   below the fold, and a bar that scrolls away is a bar nobody finds. */
.ocrflow-panel__bulk {
	position: sticky;
	bottom: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	background-color: var(--color-main-background);
	border-top: 2px solid var(--color-border);
	z-index: 1;
}

.ocrflow-panel__bulk-count {
	font-weight: bold;
	font-variant-numeric: tabular-nums;
}

.ocrflow-panel__mine-dir,
.ocrflow-panel__mine-name {
	/* Both wrap instead of ellipsizing: the point of the row is that the
	   reader can read the whole location and the whole file name. */
	overflow-wrap: anywhere;
}

.ocrflow-panel__mine-dir {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.ocrflow-panel__mine-name {
	font-weight: bold;
}

.ocrflow-panel__mine-error {
	/* The worker's own words. Monospace and quiet: it is evidence, not prose,
	   and it is the only reason an unclassified failure is not a mystery. */
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}

.ocrflow-panel__mine-text {
	overflow-wrap: anywhere;
}

.ocrflow-panel__mine-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

/* --- the report: six blocks of files ------------------------------------- */

.ocrflow-panel__note {
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.ocrflow-panel__block {
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
}

/* The heading is a row, not a paragraph: label left, total right, on the same
   baseline as the rows underneath it. */
.ocrflow-panel__block-heading {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin: 0;
	font-size: 0.95em;
	font-weight: 600;
}

.ocrflow-panel__counts {
	list-style: none;
	padding: 0;
	margin: 0;
}

/* Every sub-row is one shape and one indent: the hierarchy is the step in, not
   a second colour scheme. */
.ocrflow-panel__counts li {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	box-sizing: border-box;
	padding-block: calc(var(--default-grid-baseline, 4px) * 1);
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 4);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.ocrflow-panel__status {
	flex: 1 1 auto;
	min-width: 0;
	/* No text-transform: the labels are sentences ("read it, no text to
	   transcribe") or engine names ("by google 3.16.0"), and capitalising every
	   word turned them into Titles. */
}

.ocrflow-panel__count {
	flex: 0 0 auto;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.ocrflow-panel__counts li.is-below-bar .ocrflow-panel__status,
.ocrflow-panel__counts li.is-below-bar .ocrflow-panel__count {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

/* The one block whose numbers are a problem, not just a count. */
.ocrflow-panel__process {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.ocrflow-panel__batch {
	width: 9rem;
}

/* Hint lines sit under a control that spends money: quiet, not shouting. */
.ocrflow-panel__hint {
	margin: calc(var(--default-grid-baseline, 4px) * 1) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ocrflow-panel__block--error .ocrflow-panel__count {
	color: var(--color-error-text, var(--color-error));
}
</style>
