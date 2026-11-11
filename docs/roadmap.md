# Roadmap

Improvement backlog derived from the 2026-11-11 conformance audit, ordered.
One item per bullet, each with a one-line why. Terse on purpose: this file
must not overstate — anything not measured carries an explicit `unverified`
beside it.

1. **Trash-on-convert live acceptance** — PR #72 is unit-tested but has never
   touched a real trashbin; prove with one synthetic image that the source
   lands in `oc_files_trash` and the job still succeeds if trashing throws.
2. **stage_events production proof** — one one-page image (~$0.0015) after
   deploy shows exactly one coded row per decision on both the app and worker
   paths.
3. **Jobs retention** — `oc_ocrflow_jobs` grows unbounded; a chunked sweep
   inside ReconcileJob (already 300 s, already idempotent), index on
   `(status, updated_at)`, terminal rows older than a configurable N days.
   Safe because `oc_ocrflow_files` is a permanent per-file ledger (UNIQUE
   `file_id`) that keeps `(etag, status)` — verified from the live schema —
   and enqueue consults it via `isDoneForVersion()`.
4. **Security debt** — 8 open CodeQL alerts (log-injection cluster, partial
   SSRF on the worker URL fetch, stack-trace leak) and 1 untriaged Dependabot
   medium.
5. **Trust boundary** — AppAPI request auth unimplemented; the nginx queue
   allow-list warns instead of enforcing; the worker must not be able to act
   outside its assigned job (the `source_path` guard from #72 is the only
   check today).
6. **Typed app configuration** — the strict worker env parser is merged, but
   app config is still raw `'1'`/`'0'` `getAppValue` comparisons in
   controllers; one typed config service owning keys, defaults, validation,
   persistence.
7. **Observability** — metrics by reason code, stage durations, queue age,
   retries, paid pages; one structured readiness result; never secret values.
8. **Event-driven enqueue** — verify FIRST whether jobs are only created by
   the nightly ScanJob; if so, a `NodeCreatedEvent` listener gives
   near-realtime OCR. Unverified until that check runs.
9. **Google-SDK mypy gap** — CI typecheck misses 5 known attr-defined sites
   in `google_engine.py`.
10. **Image coverage** — SVG and HEIC are excluded today; that is a
    semantics-and-fidelity task, not an extension-list edit.
11. **D2 honesty** — while NC 35 testing stays deferred, drop max-version to
    34 so `info.xml` stops shipping an untested claim.
12. **Deploy-state document** — production trails several merged PRs; one
    place recording app commit, app version, migration state, worker image,
    readiness.
13. **Ops small fry** — Postgres collation 2.42→2.44 (`ALTER DATABASE
    nextcloud REFRESH COLLATION VERSION`), bare `docker run` exits 2,
    published image UX.

## Hardening (extreme scale)

The four hardening PRs, in order:

1. Etag compare-and-swap at result, plus a quiescence skip at enqueue.
2. `quota_deferred` (requeue without burning attempts), plus a pending-jobs
   cap.
3. Config-driven scan limits, plus a multi-row stage INSERT.
4. Retention sweep, plus advisory lock.

Then a flood proof on a synthetic 10^6-file tree with 3 workers.

## Open questions

- **Body-size limits unmeasured** (php-fpm and nginx). Closes with: a
  measurement of the largest accepted request body on both planes.
- **Trash-doubling policy question.** Closes with: a decision on whether a
  re-OCR of a file already in the trash creates a second trash entry or
  reuses the first.
- **A failed unchanged file cannot currently be reprocessed** because
  `jobForVersion` matches terminal error rows. Closes with: a design decision
  (and PR) for how a retry is expressed — e.g. a `force` flag on enqueue, or
  ignoring error rows older than N days.
