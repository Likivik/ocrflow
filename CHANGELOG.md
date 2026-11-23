# Changelog

Newest first. Dates are the day the change landed on `main`, not the day it was
written.

## Unreleased

### Added

- **The panel numbers update live.** One quiet re-read of the status every 5 s
  while the tab is visible; nothing at all while it is hidden. A failed poll
  keeps the last good numbers and says nothing — only the Refresh button
  reports errors. Push would hold a php-fpm worker hostage per open tab, so
  the panel polls.

### Fixed

- **The Process button works again.** `processCount`, `processEffective`,
  `processLabel`, `processDisabled`, `processReason` and `processEstimate` sat
  in `methods` while the template reads them like computed values — so the
  button was always disabled and its label rendered a raw function instead of
  words. They are computed now.
- **Row styling came back.** 0.1.57 moved the row lists into `FileRows.vue` and
  `BlockRows.vue`, but `Panel.vue`'s scoped styles stopped reaching inside
  them: counts glued to labels, checkboxes adrift. Each component owns its CSS
  now.

### Changed

- **Phase 3: one outcome catalog.** The 35-code vocabulary moved out of
  `RetryPolicy` into `OutcomeCatalog` — one table of code → verdict, sentence,
  row label, block. `RetryPolicy` keeps only what it decides. The two
  engine-label builders in `StatusController` are now `OutcomeCatalog::engine()`
  and `reader()`.
- **One row shape.** `FileRow` builds the per-file row for `/mine`, the
  attention list and a block's drill-down; `CategoryRow` builds the aggregate
  rows. A block row is not a file row: the types are separate so a count cannot
  be mistaken for a file.
- **One component per kind of row.** `FileRows.vue` (the worklist) and
  `BlockRows.vue` (the block rows) move out of `Panel.vue`, each with its
  contract in the header.

### Added

- `FileRowParityTest`: the same file routed through `/mine` and the attention
  list comes out with the same keys, the same code and the same sentence —
  "not processed yet 10 vs 30" cannot come back.
- `OutcomeCatalogTest` carries every assertion `VocabCopyTest` held; that file
  is gone.

### Removed

- **Phase 2c: the id-list report is deleted.** `Queue::fileReport()` and its
  four readers (`engineSplit`, `emptyCount`, `unreadableFormatCount`) are gone —
  nothing in `lib/` had called them since 0.1.46 put the panel on aggregates.
  `NeverProcessedTest` went with them; the one case it held that
  `PanelReportTest` did not (a stale job reference staying visible) was ported
  there. `Queue.php` 1446 → 1309 lines.

### Added

- **`Queue` is gone.** `lib/Db/Queue.php` deleted: 2,158 lines became six
  classes with one subject each (`JobStore`, `Ledger`, `SettlementService`,
  `DiskFiles`, `Batch`, `EnqueueService`) plus `PanelReport`. The 10 behaviour
  test files follow their subject. `QueueInventoryTest` keeps the inventory
  assertion: every one of the 62 names has exactly one home.
- **Phase 2c continues: `Attention` and `Outcomes` off `Queue`.** `Attention`
  now takes `JobStore` + `Ledger` + `EnqueueService` — one class per table it
  touches. The `StoreAttention`/`StoreOutcomes` test fixtures follow, and
  `AttentionController` takes `JobStore` for its one call. 36 → 27 Queue call
  sites.
- **Phase 2c begins: five call sites off `Queue`.** `occ ocrflow:ledger:check`
  now takes `Ledger`, `occ ocrflow:rescan` takes `JobStore`, the delete
  listener takes `JobStore`, the upload listener and the scan job take
  `EnqueueService`, and the reconcile job takes `JobStore` (its
  `CLAIM_LEASE_SECONDS` too). Behaviour unchanged; 41 call sites in 11 files
  become 36 in 6.
- **Phase 2g: the inventory is a test.** `QueueInventoryTest` asserts all 62
  Phase 2 names have exactly one home and that `Queue` only delegates what it
  still declares. It immediately found three methods still carrying real SQL in
  `Queue` (`selectOrphanStageIds`, `selectStageIdsForJobs`,
  `deleteStageRowsById`) — dead duplicates of `JobStore`'s copies; deleted.
  `Queue.php` 644 → 588 lines.
- **Phase 2f: the enqueue policy is its own class.** `EnqueueService` owns
  `enqueue` — the six refusals and the engine decision — and
  `enqueueNeverProcessed`, the two-door batch. `Queue` delegates; `Queue.php`
  923 → 825 lines.
- **Phase 2e: candidate selection and the cost estimate are their own classes.**
  `Batch` owns `neverProcessedFor` (who the process button may take, and in
  what order) and the scan-age gate; `PanelReport` owns `pageStatsFor` (what
  another batch will cost). `Queue` delegates. `Queue.php` 1019 → 923 lines.
- **Phase 2d: the settle transaction has one owner.** `SettlementService` now
  holds `finish`, `settleProduced` and the stage writers, and calls
  `Ledger::recordFile`/`writeLedger` (which moved into `Ledger`) — one result,
  three tables, from one place. `Queue` delegates. `Queue.php`
  1309 → 1019 lines; `SettlementService` 315; `Ledger` 459.
- **Phase 2b: the job log is a class.** Twenty-two `ocrflow_jobs` operations
  (`claim`, `renewClaim`, `release`, `requeueStale`, `forceRequeue`, retention
  and the scan loop breaker among them) now live in
  `OCA\OcrFlow\Storage\JobStore`; `Queue`'s same-named methods delegate, and
  the claim constants have one definition that both classes name. `Queue.php`
  1923 → 1446 lines. The retention tests now subclass `JobStore`, because
  retention is a fact about the job log, not about the queue facade.
- **Phase 2a of the storage split: the ledger is a class.** Eleven reads and
- **The panel report is computed from aggregates.** Six `COUNT`/`GROUP BY`
  queries replace "load every file id, then four chunked `IN` readers" — and the
  report is now computed once per load instead of twice (`StatusController::index()`
  and `blocks()` each called `Queue::fileReport()`).
- `occ ocrflow:report:check --compare [--user uid]` compares the aggregate report
  against an independent id-list implementation that carries its own SQL, run
  three times (legacy, aggregate, legacy) so a queue that changes mid-comparison
  is reported as UNSTABLE rather than as a mismatch.
- New `DiskFiles` holds the one copy of the disk predicate (four scopes:
  processable, empty, unreadable, base) instead of three near-copies.
- The write-time ladder (Parts A + C): `putFile` and `resultFile` now run a
  three-rung guard before writing — (1) the enqueue etag still matches, or
  (1b) the etag is the one our own earlier write left behind
  (`jobs.written_etag`, re-armed after every write), or (2) the content hash
  matches (`jobs.source_sha256`, hashed from the exact bytes `file()` served
  while streaming the download). Only a genuinely different source falls
  through to the stash. The old guard compared etags alone in `result()`
  AFTER the write — on the in-place path our own `putFile` write moved the
  etag first, so the guard fired on our own write.
- The stash: a conflicted result is written to the app data directory
  (never the user's files) and recorded in `ocrflow_pending_results`
  (UNIQUE `(file_id, etag)`; a repeat supersedes the old artifact). The job
  closes terminally as `skipped` with the new code
  `skip_source_changed_result_saved` (verdict `redo`, actor `owner`, fix
  hint pointing at the panel's Decide tab — Part B), the worker gets the
  existing terminal-skip 200, and a later `result()` call for the job is a
  no-op. `source_changed` is now an alias for the new code on read.
- Artifact retention: `pending_results_retention_days` (default 30, `0`
  disables), swept by the existing reconcile tick; expiry deletes the bytes
  and leaves the row as `discarded`, and one log line per tick carries the
  total artifact bytes.
- The trash of an image→PDF source moved from `result()` into
  `resultFile()`, after a successful `createBeside()` and under the ladder.
  The worker sends the trash intent (`source_converted`, `source_path`)
  with the result-file request.
- 0.1.8 (migration `Version001009...`): `jobs.source_sha256`,
  `jobs.written_etag`, and the `ocrflow_pending_results` table.

### Added

- Unforgeable claims: `queue/next` mints a 64-hex claim token per claim, and
  `file`, `putFile`, `resultFile`, `result` and `failure` all require it in
  `X-OcrFlow-Claim`. A missing or wrong token is a logged 409 with the
  machine code `error_worker_job_reference_stale` in the body — replacing
  today's silent discard (200, `applied: false`, nothing logged) and closing
  the hole where any holder of the shared worker secret could read any
  job's bytes by id. `Queue::finish()` guards on the token in the database
  as well.
- Worker registry (`ocrflow_workers`, landed unused in 0.1.5): the worker
  registers at startup (`POST /api/v1/worker/register`, name from
  `NC_OCR_WORKER_NAME`, its own version) and heartbeats every
  `NC_OCR_HEARTBEAT_SECONDS` (default 60). The claim stores the holder's
  row id (`claimed_by_worker_id`), and stale-claim recovery is now
  evidence-based: a claim is requeued only when its holder's heartbeat is
  stale or the holder is unknown — the old blind 900-second window on
  `claimed_at` could requeue a live worker's 30-minute PDF from under it.
  The same reconcile tick prunes worker rows whose heartbeat fell behind
  (~3x the interval); no new scheduler.
- The worker holds the token in memory for one job only, sends it on every
  call, treats a 409 as "this claim is gone" (stop, do not retry, do not
  report a failure — the report would be refused too), and classifies it
  as `error_worker_job_reference_stale`.
- The attention worklist: every job row whose reason code carries the
  `needs_fix` verdict (a broken file, missing engine credentials, a rejected
  worker secret — a problem a retry cannot fix) is surfaced to the
  administrator instead of dying in `nextcloud.log`. Retention no longer
  purges these rows — the row IS the work — while `wait`/`nothing_to_do`
  rows still age out and `ocrflow_files` is still never touched. Three
  front doors over one data source: `GET /apps/ocrflow/api/v1/attention`
  (admin only, paginated, filterable by code, error prose truncated to
  500 chars), `POST /apps/ocrflow/api/v1/attention/retry` (`{id}` or
  `{all: true}`, CSRF-checked, `all` capped by `attention_retry_cap`
  config, default 500), `occ ocrflow:errors`, plus an admin panel section
  with a count badge, per-row label/fix hint/error text/attempts, a Retry
  button and a confirmed Retry-all. The endpoint retry and
  `occ ocrflow:requeue <path>` share one retry implementation and log one
  line per retried file.

### Changed

- 0.1.6 (migration `Version001007...`) drops `ocrflow_jobs.claimed_by` —
  the worker NAME a claim used to be guarded on. It was never an identity:
  it came from a header, defaulted to `worker`, and was truncated to 64
  chars. Its last writer was removed in this release; the column had been
  kept since the 0.1.5 migration so this drop is deployable in one step.

### Added

- Bounded retention for the job tables: every 5 minutes the reconciler's
  sweep deletes terminal job rows (`done`, `skipped`, `error`) older than
  `jobs_retention_days` (default 90, `0` disables, garbage falls back to the
  default) together with their stage-event rows, capped at 500 job rows per
  run so one tick cannot lock the tables. `oc_ocrflow_files` — the per-file
  dedupe ledger — is never touched. Adds indexes on
  `ocrflow_jobs (status, finished_at)` and `ocrflow_stage_events (created_at)`
  via the 0.1.4 migration, and guards overlapping runs with the server's
  DB-backed lock provider.

### Fixed

- **Fixed: the aggregate report called `expr()->not()`, which Nextcloud's
  expression builder does not have** — so `status` answered 500 for every user
  in 0.1.46. Live files are now selected as ids (the queue's depth, not the
  library's size) and the two statuses are made disjoint in PHP; the ledger
  exclusion uses chunked `NOT IN`. The real-Nextcloud integration smoke now runs
  `occ ocrflow:report:check --compare --all`, because this is the second time a
  builder method that does not exist passed unit tests and PHPStan.
- **Fixed: the panel answered 500 on every load.** Two faults, both invisible to
  unit tests: Nextcloud's function builder has no `AVG()` (the page estimate now
  uses `SUM`/`COUNT` and divides in PHP), and its query builder refuses any
  `IN (...)` wider than 1000 expressions on *every* driver, not just Oracle — the
  7 000-file report, `occ ocrflow:ledger:check` and `requeueStale()` now walk
  their ids in chunks of 900.
- The panel's labels are labels now: engines are proper nouns (`Google`,
  `MiniMax`, `Tesseract`, `Engine not recorded`), every block row is a short
  noun phrase with the sentence moved to a tooltip, and `VOCAB` carries a
  `short` for all 35 codes (a test enforces capital, no full stop, no
  parentheses). Russian covers every panel string (it covered 11 of 66).
- **Process N files** under "Not processed yet": a number field, clamped to 1…200
  and to a daily allowance, remembered per user, with the button stating the
  effective count and a page/time estimate taken from this account's own
  history. It queues only the user's own unprocessed files, quiescence-aware,
  ordinary enqueue first and `forceRequeue` only for a file whose job row still
  exists.
- `error_worker_file_gone` is no longer a counter of its own: a file that
  vanished mid-run and came back is "not processed", because that is what it is.
- The settings panel's worklist is collapsed by default and its rows scroll
  inside a bounded box while the filters and "Select all shown" stay put; the
  heading count is the server's total, not the length of the page the panel
  happens to hold.
- A file deleted or moved before we read it is no longer listed as an error:
  `error_worker_file_gone` is counted apart and shown nowhere. A stale job
  reference still shows — the file is still there, and that is a different fact.
- The read block names its reader: "by google, no text to transcribe" instead of
  "read it, no text to transcribe", and "OCRed by OcrFlow" instead of "OCRed by
  us". No-text rows split by engine only, so two versions of one reader do not
  become two rows with one label.
- Permanent OCR failures stay failed: `enqueue()` refuses to re-queue a file
  whose per-file ledger row records a permanent failure (`broken_pdf`,
  `bad_format`, `empty_source`, `no_text_detected`, `parse_produced_nothing`,
  `save_refused`) for the same etag, so the verdict survives retention purging
  the job row — previously a file that failed for a permanent reason was
  re-queued, paid for and failed again once per retention window, forever. New
  bytes (a new etag) still earn a fresh attempt, and `occ ocrflow:requeue
  <path>` clears the verdict for one file. Ambiguous codes (transport-shaped,
  deploy-shaped, 4xx) fail open toward retrying.
- A job whose source file changed while the OCR ran no longer writes its
  (stale) result over the user's newer file, and no longer trashes the newer
  file as the "converted source" of an image→PDF conversion: the result path
  compares the file's current etag against the one recorded at enqueue and
  refuses with a terminal `skipped`/`source_changed`. Legacy rows without an
  etag keep the old behaviour.
- Folder scans no longer re-OCR a file that is still being written: a file
  whose mtime is within `scan_min_age_seconds` (default 120, garbage config
  falls back to the default) of the scan is left for a later scan instead of
  being enqueued as a new job on every etag change.

### Added

- **Administration → OCR**: a settings page for the engine and the group list,
  and a per-user switch that turns automatic OCR off for your own uploads.
  Both were reachable only through `occ`; the values are the same ones the
  commands set.
- Russian translation (`l10n/ru.json`), and the panel now uses the real
  translation function instead of a passthrough that made the interface English
  in every locale.

### Changed (breaking)

- **The OCR engine has no default.** It used to default to `google` inside the
  worker, so an instance nobody configured sent a page image of every document to
  Google Cloud Vision. The engine is now instance policy: set it with
  `occ ocrflow:engine tesseract|google|minimax` (or `none`). Until it is set,
  uploads are not queued and folder scans are skipped rather than failing later.
  Existing installations must choose an engine after upgrading.

### Added

- `AccessGate`: an admin group list (`occ config:app:set ocrflow enabled_groups
  --value='["staff"]'`) and a per-user opt-out (`auto_ocr_disabled`). An empty or
  unparseable list means everyone, so an upgrade never silently stops OCR.
- `/heartbeat` now reports `store_ready`, `jobs_depth` and `error`, and answers
  `degraded` when the worker cannot use its index.
- The worker accepts AppAPI's `APP_PORT` and `APP_SECRET` in addition to
  `NC_OCR_HEALTH_PORT` and `NC_OCR_WORKER_SECRET`.
- `docs/deploy/`: docker-compose reference, a NixOS native unit, and the HaRP
  daemon-registration command.

### Fixed

- SIGTERM/SIGINT now stop the scheduler (bounded) and requeue the in-flight job.
  Before, the row stayed `running` until the next start, so a paid cloud OCR call
  could be made twice.
- `stats()["claimable"]` was computed in an exception handler, so the healthy path
  never set it and callers fell back to `queued` — which includes a retry waiting
  out its backoff.
- The image smoke treated the first `/heartbeat` as success; the probe starts
  before the start-up gates, so a worker that then exited 2 still answered first.
  It now waits past the gates and requires `transport: api` in the log.

## 0.1.0

- First tagged state: PHP app owns the queue and per-file state; the stateless
  Python worker does download, classification, rasterising, OCR and text-layer
  embedding.
