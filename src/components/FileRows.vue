<template>
	<!--
	  One row per file — the worklist and the drill-down share this.

	  Contract:
	    props   rows        FileRow[]  — id, file_id, path, text, error, age,
	                                   attempts, retryable
	            selection   Set/array of selected ids
	            retrying    the row key currently retrying, or null
	            busy        disables every action
	    events  retry(row)  delete(row)  select(row, checked)
	    states  empty → the `empty` slot; populated → one li per row

	  The actions are the only verbs a row has: Open is a plain link to
	  Nextcloud's viewer (the file is Nextcloud's, not ours), Retry only where a
	  second attempt can change the answer, Delete always with a confirm in the
	  parent.
	-->
	<ul class="ocrflow-panel__mine">
		<li
			v-for="row in rows"
			:key="rowKey(row)"
			class="ocrflow-panel__mine-row"
			:class="{ 'is-selected': isSelected(row) }">
			<NcCheckboxRadioSwitch
				type="checkbox"
				:checked="isSelected(row)"
				:aria-label="t('Select {path}', { path: shortPath(row.path) })"
				@update:checked="(checked) => $emit('select', row, checked)" />
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
				<!-- Open is a plain link: the file is Nextcloud's, not ours,
				     and the viewer is the right place to look at it. -->
				<NcButton
					v-if="row.file_id"
					:href="fileUrl(row)"
					target="_blank"
					rel="noopener">
					{{ t('Open') }}
				</NcButton>
				<!-- Retry only where a second attempt can change the answer;
				     a stamp needs a decision, not another run. -->
				<NcButton v-if="row.retryable" :disabled="busy" @click="$emit('retry', row)">
					{{ retrying === rowKey(row) ? t('Retrying…') : t('Retry') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="$emit('delete', row)">
					{{ t('Delete') }}
				</NcButton>
			</div>
		</li>
	</ul>
</template>

<script>
import { t as translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

export default {
	name: 'FileRows',
	components: { NcButton, NcCheckboxRadioSwitch },
	props: {
		rows: { type: Array, required: true },
		selection: { type: Array, default: () => [] },
		retrying: { type: [String, Number], default: null },
		busy: { type: Boolean, default: false },
	},
	emits: ['retry', 'delete', 'select'],
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
			// The row shows where the file lives and what it is called, as two
			// pieces rather than one truncated string: the directory is long
			// and generic, the file name is what the reader is looking for, and
			// neither may be cut off. The `/alice/files/` prefix is noise inside
			// the user's own settings, so it is dropped.
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
		isSelected(row) {
			return this.selection.includes(this.rowKey(row))
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
	},
}
</script>

<style scoped>


/* Your own rows: path first (it is what the user recognises), then the fix
   hint, then the provenance. The actions stay on the inline end and never
   shrink the text to nothing. */
.ocrflow-panel__mine{
	list-style: none;
	padding: 0;
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
}


.ocrflow-panel__mine-row{
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 3) 0;
	border-bottom: 1px solid var(--color-border);
}


/* Flexible text column: it must be allowed to shrink (min-width: 0) so the
   path ellipsizes, and the buttons must never be the thing that shrinks — a
   squeezed NcButton truncates its label to "Re…", which is unreadable. */
.ocrflow-panel__mine-text{
	display: flex;
	min-width: 0;
	flex: 1 1 auto;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}


.ocrflow-panel__mine-actions{
	display: flex;
	flex: 0 0 auto;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: flex-start;
}


/* On a phone a row cannot hold text and three actions side by side: stack
   them, with the actions wrapping rather than truncating. */
@media (max-width: 600px) {
.ocrflow-panel__mine-row{
		flex-direction: column;
		align-items: stretch;
		gap: calc(var(--default-grid-baseline, 4px) * 2);
	}

.ocrflow-panel__mine-actions{
		flex-wrap: wrap;
	}}


.ocrflow-panel__mine-dir,
.ocrflow-panel__mine-name{
	/* Both wrap instead of ellipsizing: the point of the row is that the
	   reader can read the whole location and the whole file name. */
	overflow-wrap: anywhere;
}


.ocrflow-panel__mine-dir{
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}


.ocrflow-panel__mine-name{
	font-weight: bold;
}


.ocrflow-panel__mine-error{
	/* The worker's own words. Monospace and quiet: it is evidence, not prose,
	   and it is the only reason an unclassified failure is not a mystery. */
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
	font-size: var(--font-size-small, 13px);
	overflow-wrap: anywhere;
}


.ocrflow-panel__mine-text{
	overflow-wrap: anywhere;
}


.ocrflow-panel__mine-meta{
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}


.ocrflow-panel__count{
	flex: 0 0 auto;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}
</style>
