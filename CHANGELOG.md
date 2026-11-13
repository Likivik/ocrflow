# Changelog

Newest first. Dates are the day the change landed on `main`, not the day it was
written.

## Unreleased

### Added

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
