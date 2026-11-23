<template>
	<!--
	  Aggregate rows for one block: a label and a count, nothing else.

	  Contract:
	    props   rows     CategoryRow[] — key, label, count, title, below_bar
	            loading  shows a skeleton instead of rows
	            error    shows the server's message instead of rows
	    events  none — a count is not a file, so a block row has no actions
	    states  loading skeleton · empty (no slot: the parent prints its own
	            sentence) · error card · populated

	  A block row has no fileId and no path on purpose: the day it grows one is
	  the day a drill-down is forgotten and a block is counted as a file.
	-->
	<ul v-if="rows.length" class="ocrflow-panel__counts">
		<li
			v-for="row in rows"
			:key="row.key"
			:class="{ 'is-below-bar': row.below_bar }"
			:title="row.title || row.key">
			<span class="ocrflow-panel__status">{{ t(row.label) }}</span>
			<span class="ocrflow-panel__count">{{ row.count }}</span>
		</li>
	</ul>
</template>

<script>
import { t as translate } from '@nextcloud/l10n'

export default {
	name: 'BlockRows',
	props: {
		rows: { type: Array, required: true },
		loading: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},
	methods: {
		t(text, params) {
			return translate('ocrflow', text, params)
		},
	},
}
</script>
