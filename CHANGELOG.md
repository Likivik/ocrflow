# Changelog

Newest first. Dates are the day the change landed on `main`, not the day it was
written.

## Unreleased

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
