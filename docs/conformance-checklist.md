# App conformance checklist

A standing benchmark for this app, so conformance can be re-assessed instead of
re-argued. Every item is a **verification procedure**: what to check, where the
rule comes from, how to check it here, and its status at the last assessment.

- **Last assessed:** 2026-11-11
- **Audited commit:** d04e046b
- **Instance at assessment:** Nextcloud 34.0.4.1 (app declares `max-version="35"`, never run on 35), PHP 8.4.26 (fpm)
- **Status legend:** `PASS` verified · `FAIL` verified failing · `VERIFY` not yet checked · `DEFERRED` consciously skipped

Sources (all read 2026-11-11):

- Nextcloud 35 Developer Manual — <https://docs.nextcloud.com/server/stable/developer_manual/>
- App store rules — <https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html>
- `info.xml` schema — <https://apps.nextcloud.com/schema/apps/info.xsd>
- Release process — `.../app_publishing_maintenance/release_process.html`
- Dependency management — `.../app_development/dependency_management.html`
- Coding standards — `.../getting_started/coding_standards/`
- "Nextcloud app development beginner's guide" — <https://nextcloud.com/blog/nextcloud-app-development-beginners-guide/>
  (the only source that reports real production failure modes with numbers)

---

## A. Metadata and packaging

**A1. `info.xml` validates against the app store XSD — element *order* matters.** `FAIL`
`xmllint --noout --schema <xsd> appinfo/info.xml`
Ordering errors are the documented cause of most validation failures. Measured on
d04e046b: two validity errors — `:42 Element 'admin': This element is not expected`,
`:45 Element 'commands': This element is not expected`.

**A2. Declare `dependencies/php` and `dependencies/database`.** `FAIL`
Both are checked at *install* time, not update time, so an unsupported runtime is
discovered only on a fresh install. Measured: `grep -cE '<php>|<database>' appinfo/info.xml`
returns `0` — neither is declared; only `nextcloud min-version="32" max-version="35"`
is declared.

**A3. Provide a `screenshot`.** `FAIL`
Cosmetic while private; required for a credible app store page. The element is
absent from `appinfo/info.xml`.

**A4. Every migration ships with a higher `info.xml` version.** `PASS`
Established by incident: a schema change without a version bump never runs. Re-verify by
diffing new `lib/Migration/*` files against `<version>` in `appinfo/info.xml`.
Note: `lib/Migration/` holds 001000, 001001, 001002, 001004 — there is no 001003.
That numbering gap is harmless (Nextcloud keys migrations by filename timestamp,
not sequence); do not renumber.

**A5. The shipped package contains no development files.** `PASS`
Rule: remove `tests`, `.github`, `node_modules`, `src`, `phpunit.xml`, `phpstan.neon`,
`flake.nix`, `package*.json` from the artefact. Measured on the live deployed tree:
`/nix/store/cm4ynq2xg5s4wa0p8xlznnfh0rzqf3jp-nextcloud-34.0.4-with-apps/nix-apps/ocrflow`
contains only `appinfo`, `composer.json`, `img`, `js`, `l10n`, `lib`, `LICENSE`,
`README.md`, `templates` — no tests/.github/node_modules/src/phpstan.neon/phpunit.xml/nix/docs.
Measured on the 0.1.2 tree; the packaging files are unchanged by the later app PRs.

**A6. Licence on the approved list.** `FAIL`
Must read `AGPL-3.0-or-later` (not the `agpl` alias) for apps targeting v31+.
Note the failure mode precisely: `agpl` **is** still accepted by the published XSD,
so this will never fail schema validation. It fails the app-publishing rule for
apps targeting v31+, which requires the full identifier `AGPL-3.0-or-later`.
Acceptance is the element reading the full string, not xmllint passing.

## B. Technical soundness

**B1. Only the public API is used.** `PASS`
`grep -rn "use OC\\\\" lib/` — internal classes are `OC\`, public are `OCP\`. Any hit is a
future breakage on a Nextcloud upgrade. Measured: 0 hits in `lib/`.

**B2. No direct calls into other apps; react to events.** `PASS`
`grep -rn "OCA\\\\" lib/` outside our own namespace. Measured: 0 hits outside
our own namespace.

**B3. Emergency access paths authenticated, compared in constant time.** `PASS`
Worker secret compared with `hash_equals`. Known gap: AppAPI request auth unimplemented.

## C. Data at scale

**C1. Migrations must not do heavy data work inline; queue a one-time background job.** `PASS`
Documented case: a Nextcloud migration took **6-7 hours** on a large instance because it
was written against small test data. For each migration, bound rows x cost.
Measured: all four shipped migrations are DDL; the one data migration ever written
was deleted in PR #80 because the live check showed zero `oc_preferences` rows.

**C2. Never traverse whole file trees in one unbounded pass; use folder search with limit and offset.** `FAIL`
Measured in `lib/BackgroundJob/ScanJob.php:71-90`: when a folder node is popped,
its children are listed unconditionally, after/below the `$seen < self::MAX_FILES`
guard — so a folder with 100k children costs a 100k-row listing even at the cap
(`MAX_FILES = 20000`, ScanJob.php:29). Also recorded: a hypothesis that the outer
loop plus recursion double-visits nodes was tested against the code and REFUTED —
each top-level node gets its own stack, every node is visited once.

**C3. Do not loop over all users in one pass.** `PASS`

**C4. Chunk `IN` clauses at 1,000 items.** `PASS`
Oracle rejects more; MariaDB and SQLite never show it, so it surfaces only on someone
else's instance. Portability insurance here, not a live bug (we run Postgres).
Measured: no `IN (` array parameters in `lib/Db` or `lib/Service`.

**C5. No N+1 queries — batch lookups.** `PASS`
Measured in `lib/Db/Queue.php:442/:482/:526`: the loops bind parameters or aggregate
one grouped query (`groupBy('status')`); no per-row SELECT in a loop.

**C6. Background jobs must not assume precise timing.** `PASS`
Cron runs every 5 or 15 minutes depending on instance configuration; AJAX mode has no
guarantee at all. Note: `lib/BackgroundJob/ReconcileJob.php:34` `setInterval(300)` is
a hint, not a guarantee. Measured: nextcloud-cron.timer is active and fires every
5 min; `reserved_at` is epoch-0 on every `oc_jobs` row — i.e. NC 34 shows no
exclusive reservation, so a job outliving 5 min could overlap itself.

## D. Testing and compatibility

**D1. Unit tests plus integration tests against a real Nextcloud.** `PASS`
PHPUnit + pytest + a real-Nextcloud CI job. Measured on d04e046b via the check-runs
API: 9 runs, all success.

**D2. Test against the current *and* previous major.** `DEFERRED`
Skipped on 2026-11-11 by decision. Note for whoever picks it up: the app declares
`max-version="35"` while the instance runs 34.0.4 — the claim is untested until this runs.

## E. User interface and design

**E1. Follow design guidelines; use `@nextcloud/vue`.** `PASS`
Measured: `package.json` has `"@nextcloud/vue": "^9.14.0"` and CI runs a
frontend-bundle-current job, green on the pinned SHA. The shared eslint config also
ships a plugin that flags deprecated/removed APIs.

**E2. User data is sacred: prefer undo over confirmation dialogs.** `VERIFY`
Relevant to image-to-PDF conversion and the trash-on-convert change.

**E3. Application state always visible — loading, progress, failure.** `VERIFY`
Skips must be distinguishable from failures in the panel.

## F. Release hygiene

**F1. Semver, and a CHANGELOG entry per release.** `PARTIAL`
Measured: `CHANGELOG.md` exists, newest-first, has an Unreleased section with real
entries — but 0.1.1/0.1.2/0.1.3 have no own sections.

**F2. Dev tools isolated from production dependencies.** `PASS`
Measured: `composer.json` `require` is empty (zero runtime PHP deps); `require-dev`
holds doctrine/dbal, nextcloud/ocp, phpstan/phpstan, phpunit/phpunit, symfony/console;
`vendor/` does not ship in the artefact.

## G. Uninstall and data lifecycle

**G1. An app must remove its data on uninstall.** `FAIL`
`grep -rn 'cleanup\|uninstall\|preDelete\|IDeletable' lib/ appinfo/` returns
nothing. Measured: the app leaves `oc_ocrflow_jobs`, `oc_ocrflow_files`,
`oc_ocrflow_stage_events`, `oc_ocrflow_settings`, `oc_ocrflow_workers` and its
`oc_appconfig` rows behind. Fix shape: an `occ ocrflow:cleanup` command, guarded
by `--yes`, dropping the app tables and appconfig rows; the app already declares
`<commands>` (WorkerSecret, Engine), so this is cheap.

---

## Re-assessing

Run the commands in each item, update the status, bump **Last assessed**, and note the
instance version. An item changes status only with a command output behind it.

---

---

## Lessons learned

Failures that actually cost time in this app, with the rule each one produced:
see **[lessons.md](lessons.md)**. They are kept separate on purpose — this file is a
benchmark you can re-run, that file is history you should read once.
