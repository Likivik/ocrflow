# Outcome blocks — the panel answers "what happened to my files"

**Goal:** replace the run counters (`done` / `error` / `skipped`) in the Files panel
with five blocks that describe the **files**, each expanding into the rows that
add up to it.

**Architecture:** the block is a property of the outcome **code**, not of the job
row's `status` and not of the run. One table in PHP maps `code -> block`; the
status endpoint returns the blocks already grouped and labelled; the panel is a
dumb reader that draws whatever it is given. No schema change, no re-queue, no
rescan, no provider spend.

**Tech stack:** PHP 8.4 (Nextcloud app, `RetryPolicy`, `Queue`, `StatusController`),
Vue 3 (`Panel.vue`), webpack bundle, PHPUnit, PHPStan.

---

## Why not "status"

Today the panel groups by `oc_ocrflow_jobs.status`. That is a word about a *run*,
and three consequences follow:

1. Seven live rows are in the wrong block: 5 × `error_file_broken` and
   2 × `error_source_file_zero_bytes` carry `status='skipped'` because the skip
   path wrote them, while their verdict is "needs a person".
2. `done` hides who read the file; `skipped` hides why.
3. Rows are counted, so a file read once and refused once appears twice. Measured:
   2,647 "already OCRed by us" rows point at the same files as the 2,673 `done`
   rows — union is 3,252 **files**.

The code already carries the verdict (`RetryPolicy::VOCAB`), the engine and its
version are in the per-file ledger (`ocrflow_files.engine`, `engine_version`,
never purged), and the reason is already a code. So the report needs no new data,
only a mapping and one honest dedupe.

## The blocks (measured 2026-11-18, file level, on this instance)

Every block counts **files**, and every file is counted once: the source of truth
is `oc_filecache` for "what is on disk", and the **latest** job row per file for
"what happened to it". Counting rows instead made a file read once and refused
once appear twice; counting per status put 7 rows in the wrong block.

Processable on disk: **7,112** files (`files/…`, size > 0, ext in
pdf/jpg/jpeg/png/webp/heic — the worker's own list).

| Block | Count | Sub-rows |
|---|---|---|
| **Not processed yet** | 30 | none — files on disk in our formats that have never been queued |
| **In the queue** | 0 | in the queue 0 · being read now 0 (rows in `queued`/`claimed`) |
| **OCRed by us** | 2,657 | by google 3.16.0 1,630 · by google, version not recorded 1,012 · read, no text found 15 |
| **Born digital** | 2,512 | none — the text layer is the publisher's, not ours |
| **Classified as image** | 1,908 | none |
| **Can't read** | 5 | this file is damaged 5 |

30 + 0 + 2,657 + 2,512 + 1,908 + 5 = **7,112**, which is the whole on-disk set.

Outside that set, reported as a footnote because they exist but are not
processable: **5 empty files (0 bytes)** and **2 TIFF certificates**.

`file_gone` (2 rows) is **not** in the panel at all: nothing was spent and nothing
can be done. The case worth seeing is the opposite one — a file deleted *while*
its pages were already read, where money was spent and no result could be
written; that lands as `error_result_write_failed` in **Can't read**.

"No text found" moved out of Skipped into the OCRed block (15 files): Google read
them and there was nothing to transcribe. All four sampled files are signatures
and logos — two of them are named like forms.

Note found while measuring: the worker accepts `.tiff` but not `.tif`
(`webhook_server.py:126`), so the two 26 MB certificates were refused over four
letters. One-line fix, separate commit.

## Code -> block

| Block | Codes |
|---|---|
| `error` | every code whose `RetryPolicy` verdict is `needs_fix` or `drop` (`error_file_broken`, `error_source_file_zero_bytes`, `error_worker_file_gone`, `error_image_too_large`, `error_worker_request_malformed`, …) |
| `read` | `skip_ocred_by_ocrflow` + every `done` row (file-level union) |
| `born_digital` | `skip_text_native` |
| `image` | `skip_classifier_says_photo` |
| `read` also contains | `skip_ocred_by_ocrflow_found_nothing` (read, no text) |
| `unreadable` | `skip_file_type_unsupported` (.tif today) — outside the processable set |
| `skipped` (fallback only) | any code the table does not know, shown with its own spelling |

`error_worker_file_gone` keeps verdict `drop` but sits in **Errors**: the file
did not get read, and the owner benefits from seeing the count. It stays
non-actionable (no retry offered).

Any code the table does not know falls into `skipped` with its own raw code as
the label — the report must never silently drop a row.

---

## Tasks

### Task 1: block table in RetryPolicy

**Objective:** one place answers "which block is this code in".

**Files:**
- Modify: `lib/Service/RetryPolicy.php`
- Test: `tests/Unit/Service/RetryPolicyBlocksTest.php` (new)

**Step 1: write the failing test**

```php
public function testEveryKnownCodeHasABlock(): void
{
    $policy = new RetryPolicy();
    foreach (['skip_text_native', 'skip_classifier_says_photo', 'skip_ocred_by_ocrflow',
        'skip_ocred_by_ocrflow_found_nothing', 'skip_file_type_unsupported',
        'error_file_broken', 'error_source_file_zero_bytes', 'error_worker_file_gone'] as $code) {
        $this->assertContains($policy->blockOf($code), ['error', 'read', 'born_digital', 'image', 'skipped']);
    }
}

public function testAnUnknownCodeStillGetsABlock(): void
{
    $this->assertSame('skipped', (new RetryPolicy())->blockOf('made_up_code'));
}
```

**Step 2: run it**

`nix shell nixpkgs#php83 -c php vendor/bin/phpunit --no-coverage --filter RetryPolicyBlocks`
Expected: FAIL — `Call to undefined method blockOf()`.

**Step 3: implement**

```php
public const BLOCKS = [
    'error' => 'Errors',
    'read' => 'OCRed by us',
    'born_digital' => 'Born digital',
    'image' => 'Classified as image',
    'skipped' => 'Skipped',
];

private const CODE_BLOCKS = [
    'skip_text_native' => 'born_digital',
    'skip_classifier_says_photo' => 'image',
    'skip_ocred_by_ocrflow' => 'read',
    'skip_ocred_by_ocrflow_found_nothing' => 'skipped',
    'skip_file_type_unsupported' => 'skipped',
    'error_file_broken' => 'error',
    'error_source_file_zero_bytes' => 'error',
    'error_worker_file_gone' => 'error',
    'error_image_too_large' => 'error',
    'error_worker_request_malformed' => 'error',
    'error_worker_sent_unknown_code' => 'skipped',
];

public function blockOf(?string $code): string
{
    $canonical = $this->canonical($code);
    return self::CODE_BLOCKS[$canonical] ?? (str_starts_with($canonical, 'error_') ? 'error' : 'skipped');
}
```

**Step 4: run it** — expected PASS, and the existing `RetryPolicyOutcomesTest`
stays green (`blockOf` is additive).

**Step 5: commit** — `feat(vocabulary): every outcome code names the block it belongs to`

### Task 2: counts per block, files counted once

**Objective:** the SQL that answers the five blocks, with the read block deduped
per file and split by engine + version.

**Files:**
- Modify: `lib/Db/Queue.php` (replace `skippedByReason()`, `errorReasons()`,
  `provenanceCounts()` with `blockCounts()`; keep the old three private or delete
  them — nothing else calls them after Task 3)
- Test: `tests/Unit/Controller/StatusBlocksTest.php` (new, mock-based)

**Step 1:** a failing controller test asserting the shape

```php
$queue->method('blockCounts')->willReturn([
    ['block' => 'error', 'rows' => [['code' => 'error_source_file_zero_bytes', 'count' => 5]]],
    ['block' => 'read', 'rows' => [['code' => 'skip_ocred_by_ocrflow', 'engine' => 'google', 'version' => '3.16.0', 'count' => 1630]]],
]);
$blocks = $controller->index()->getData()['blocks'];
$this->assertSame('Errors', $blocks[0]['label']);
$this->assertSame(12, $blocks[0]['count']);   // sum of rows
```

**Step 2:** implement `Queue::blockCounts()`

- `error` / `born_digital` / `image` / `skipped`: `GROUP BY reason_code` on
  `ocrflow_jobs`, then bucketed in PHP through `blockOf()` (cheap: a few dozen
  codes; the SQL stays a single grouped scan).
- `read`: one UNION query, per file:

```sql
SELECT count(*) FROM (
  SELECT file_id FROM oc_ocrflow_jobs WHERE status = 'done'
  UNION
  SELECT file_id FROM oc_ocrflow_jobs WHERE reason_code = 'skip_ocred_by_ocrflow'
) u
```

  then the engine split by left-joining `ocrflow_files` on `file_id`, falling
  back to the newest `done` row's `engine` when the ledger has none.
- Return `[ ['block'=>..., 'rows'=>[ ['code'=>..., 'label'=>..., 'count'=>...] ] ] ]`
  ordered the same as `RetryPolicy::BLOCKS`.

**Step 3:** run the new test plus `QueueController` and `Outcomes` suites.

**Step 4: commit** — `feat(queue): block counts, with files counted once`

### Task 3: the endpoint

**Objective:** `/status` answers `blocks`, nothing else about outcomes.

**Files:**
- Modify: `lib/Controller/StatusController.php` (delete `skipReasons()`,
  `errorReasons()`, `readBy()`, the `skip_reasons` / `error_reasons` / `read_by`
  keys, and `read_by`'s below-bar logic moves into the read block's rows)
- Test: `tests/Unit/Controller/StatusBlocksTest.php`

**Step 1:** extend the test above with the below-bar rule: a read row carries
`below_bar: true` only when `version !== null && !meetsBar(engine, version)`.

**Step 2:** implement — one call, `'blocks' => $this->blocks()`, which maps
`Queue::blockCounts()` and attaches labels (`RetryPolicy::entry($code)['text']`,
falling back to the raw code for an unknown one).

**Step 3:** keep `queue` for the filter chips (`mine`/`errors`/`all`) — it is the
only consumer left, and removing it is a separate concern.

**Step 4: commit** — `feat(status): five outcome blocks instead of three run counters`

### Task 4: the panel

**Objective:** five blocks, top-down, each with its sub-rows.

**Files:** `src/Panel.vue`, `js/ocrflow-panel.js` (built)

**Step 1:** replace the three `<section>` blocks from #138 with one loop:

```html
<section v-for="block in blocks" :key="block.id" class="ocrflow-panel__block">
    <h4 class="ocrflow-panel__block-heading">
        {{ t(block.label) }} <span class="ocrflow-panel__count">{{ block.count }}</span>
    </h4>
    <ul v-if="block.rows.length" class="ocrflow-panel__counts">
        <li v-for="row in block.rows" :key="block.id + '-' + row.key"
            :class="{ 'is-below-bar': row.below_bar }" :title="row.code">
            <span class="ocrflow-panel__status">{{ t(row.label) }}</span>
            <span class="ocrflow-panel__count">{{ row.count }}</span>
        </li>
    </ul>
</section>
<p v-if="!blocks.length" class="ocrflow-panel__empty">{{ t('Nothing has been queued yet.') }}</p>
```

**Step 2:** `refresh()` reads `data.ocs.data.blocks` — and this is exactly the
line that went missing in 0.1.32, so verify it in the bundle, not in the source:

```bash
npm run build
grep -c 'blocks' js/ocrflow-panel.js   # expect >= 1
```

**Step 3:** `npm run build`, then a manual check on poweredge after deploy: the
five headings, the sub-rows, and their sums.

**Step 4: commit** — `feat(panel): five outcome blocks; 0.1.34`

### Task 5: ship

- Rebase `feat/read-by` (#138) onto `main@origin`, or close it and open a fresh
  branch — #138 carries the three-block version of the same work.
- Gates: `phpunit` (426 + new), `phpstan`, `npm run build`, bundle grep.
- Merge, deploy, then confirm on the live page: 12 / 3,252 / 2,514 / 1,912 / 17.

---

## Acceptance

1. The panel shows five blocks with the numbers in the table above, and each
   block's sub-rows sum to its total.
2. A file read by us appears once under `OCRed by us`, never again under
   `Skipped`.
3. The 7 rows currently misfiled by `status` appear under **Errors** — proven by
   the code->block table, not by rewriting rows.
4. `RetryPolicyOutcomesTest` and all 426 existing tests stay green.
5. The shipped bundle contains the fetch for `blocks` (the 0.1.32 failure mode).

## Risks

- **Cost of the read query.** A UNION of two grouped scans over `ocrflow_jobs`
  (≈9.8k rows today). Fine now; if the table grows past a million rows the block
  counts want a materialised summary. Not now (YAGNI).
- **Ledger gaps.** 1,014 files OCRed by us have no engine in the ledger; 605 have
  no version. Shown as "version not recorded", never as below bar. If that need
  ever becomes real, backfill from the stamp — a separate concern.
- **Named-block drift.** If someone adds a code and forgets `CODE_BLOCKS`, the
  fallback puts it in `skipped` with its own spelling — visible, not silent. The
  test asserts every code in `VOCAB` has an explicit entry.

## Open questions

- ~~Where does `file_gone` go?~~ Answered: out of the panel; the log keeps it.
- ~~Russian block names?~~ Answered: English.
