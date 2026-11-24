// The worklist's pure core: paging, merging and the query shape.
//
// Everything here is arithmetic over rows the server already ordered — kept
// out of the Vue component so it can be tested, and so the component cannot
// quietly re-invent paging math that disagrees with the server.

/**
 * One row's identity. The server serves two facts about one file (a failed
 * job and a worth-redoing file) as two rows with different origins — the key
 * is the pair, so both survive a merge while a true duplicate does not.
 */
export function rowKey(row) {
	return `${row?.origin ?? ''}:${row?.id ?? ''}`
}

/**
 * The query for one page. `bucket` and `q` ride to the server: a filter that
 * only hides rows client-side makes the chips count a page while the heading
 * counts the world.
 */
export function pageQuery(state) {
	const params = new URLSearchParams()
	params.set('limit', String(Math.max(1, Number(state?.limit) || 50)))
	params.set('offset', String(Math.max(0, Number(state?.offset) || 0)))
	if (state?.bucket && state.bucket !== 'all') {
		params.set('bucket', String(state.bucket))
	}
	if (state?.q) {
		params.set('q', String(state.q))
	}
	return params.toString()
}

/**
 * Append a fetched page to what is already shown, dropping any row the
 * server sent twice (a row that changed sides between two fetches). Order is
 * the server's; this never sorts.
 */
export function mergePages(previous, next) {
	const seen = new Set()
	const rows = []
	for (const row of [...(previous ?? []), ...(next ?? [])]) {
		const key = rowKey(row)
		if (seen.has(key)) {
			continue
		}
		seen.add(key)
		rows.push(row)
	}
	return rows
}

/**
 * Where the next page starts, or null when the list is fully shown. The
 * offset is the number loaded, because the server's order is total: the
 * first `loaded` rows of the world are exactly what is on the screen.
 */
export function nextOffset(loaded, total) {
	const shown = Number(loaded) || 0
	const whole = Number(total) || 0
	return shown > 0 && shown < whole ? shown : null
}
