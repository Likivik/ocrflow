// The worklist's pure core: paging, merging and the query shape.
//
// The section lied about its own size three ways before this existed. These
// few functions are the arithmetic behind every number on that screen, so
// they are the part worth pinning: dedupe, offset math, query shape.

import { describe, expect, it } from 'vitest'
import { mergePages, nextOffset, pageQuery, rowKey } from '../../src/lib/worklist.js'

const row = (id, origin = 'job') => ({ id, origin, path: 'alice/files/' + origin + '-' + id + '.pdf' })

describe('rowKey', () => {
	it('keeps the two facts about one file apart', () => {
		expect(rowKey(row(5, 'job'))).not.toBe(rowKey(row(5, 'ledger')))
	})
})

describe('pageQuery', () => {
	it('always sends limit and offset', () => {
		expect(pageQuery({ limit: 50, offset: 100 })).toBe('limit=50&offset=100')
	})

	it('sends bucket and q to the server, never filters locally', () => {
		expect(pageQuery({ limit: 50, offset: 0, bucket: 'redo', q: '2019' })).toBe('limit=50&offset=0&bucket=redo&q=2019')
	})

	it('omits the all chip and an empty search', () => {
		expect(pageQuery({ limit: 50, offset: 0, bucket: 'all', q: '' })).toBe('limit=50&offset=0')
	})
})

describe('mergePages', () => {
	it('appends a fetched page and drops a row the server sent twice', () => {
		const shown = [row(1), row(2)]
		const fetched = [row(2), row(3)]
		expect(mergePages(shown, fetched).map(rowKey)).toEqual(['job:1', 'job:2', 'job:3'])
	})

	it('keeps both facts about one file', () => {
		const merged = mergePages([row(5, 'job')], [row(5, 'ledger')])
		expect(merged.map(rowKey)).toEqual(['job:5', 'ledger:5'])
	})
})

describe('nextOffset', () => {
	it('is where the shown rows end', () => {
		expect(nextOffset(25, 30)).toBe(25)
	})

	it('is null when the world is shown', () => {
		expect(nextOffset(30, 30)).toBeNull()
		expect(nextOffset(0, 0)).toBeNull()
	})
})
