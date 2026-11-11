# App conformance checklist

A standing benchmark for this app, so conformance can be re-assessed instead of
re-argued. Every item is a **verification procedure**: what to check, where the
rule comes from, how to check it here, and its status at the last assessment.

- **Last assessed:** 2026-11-11
- **Instance at assessment:** Nextcloud 34.0.4.1 (app declares `max-version="35"`, never run on 35)
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

**A1. `info.xml` validates against the app store XSD — element *order* matters.** `VERIFY`
`xmllint --noout --schema <(curl -s https://apps.nextcloud.com/schema/apps/info.xsd) appinfo/info.xml`
Ordering errors are the documented cause of most validation failures.

**A2. Declare `dependencies/php` and `dependencies/database`.** `FAIL`
Both are checked at *install* time, not update time, so an unsupported runtime is
discovered only on a fresh install. Today only `nextcloud min-version="32" max-version="35"`
is declared.

**A3. Provide a `screenshot`.** `FAIL`
Cosmetic while private; required for a credible app store page.

**A4. Every migration ships with a higher `info.xml` version.** `PASS`
Established by incident: a schema change without a version bump never runs. Re-verify by
diffing new `lib/Migration/*` files against `<version>` in `appinfo/info.xml`.

**A5. The shipped package contains no development files.** `VERIFY`
Rule: remove `tests`, `.github`, `node_modules`, `src`, `phpunit.xml`, `phpstan.neon`,
`flake.nix`, `package*.json` from the artefact. Check the flake `installPhase` / the
resulting store path — the repo legitimately contains all of them.

**A6. Licence on the approved list.** `PASS`
Must read `AGPL-3.0-or-later` (not the `agpl` alias) for apps targeting v31+.

## B. Technical soundness

**B1. Only the public API is used.** `VERIFY`
`grep -rn "use OC\\\\" lib/` — internal classes are `OC\`, public are `OCP\`. Any hit is a
future breakage on a Nextcloud upgrade.

**B2. No direct calls into other apps; react to events.** `VERIFY`
`grep -rn "OCA\\\\" lib/` outside our own namespace.

**B3. Emergency access paths authenticated, compared in constant time.** `PASS`
Worker secret compared with `hash_equals`. Known gap: AppAPI request auth unimplemented.

## C. Data at scale

**C1. Migrations must not do heavy data work inline; queue a one-time background job.** `VERIFY`
Documented case: a Nextcloud migration took **6-7 hours** on a large instance because it
was written against small test data. For each migration, bound rows x cost.

**C2. Never traverse whole file trees in a request; use folder search with limit and offset.** `VERIFY`

**C3. Do not loop over all users in one pass.** `VERIFY`

**C4. Chunk `IN` clauses at 1,000 items.** `VERIFY`
Oracle rejects more; MariaDB and SQLite never show it, so it surfaces only on someone
else's instance. Portability insurance here, not a live bug (we run Postgres).

**C5. No N+1 queries — batch lookups.** `VERIFY`

**C6. Background jobs must not assume precise timing.** `VERIFY`
Cron runs every 5 or 15 minutes depending on instance configuration; AJAX mode has no
guarantee at all.

## D. Testing and compatibility

**D1. Unit tests plus integration tests against a real Nextcloud.** `PASS`
PHPUnit + pytest + a real-Nextcloud CI job.

**D2. Test against the current *and* previous major.** `DEFERRED`
Skipped on 2026-11-11 by decision. Note for whoever picks it up: the app declares
`max-version="35"` while the instance runs 34.0.4 — the claim is untested until this runs.

## E. User interface and design

**E1. Follow design guidelines; use `@nextcloud/vue`.** `VERIFY`
The shared eslint config also ships a plugin that flags deprecated/removed APIs.

**E2. User data is sacred: prefer undo over confirmation dialogs.** `VERIFY`
Relevant to image-to-PDF conversion and the trash-on-convert change.

**E3. Application state always visible — loading, progress, failure.** `VERIFY`
Skips must be distinguishable from failures in the panel.

## F. Release hygiene

**F1. Semver, and a CHANGELOG entry per release.** `VERIFY`

**F2. Dev tools isolated from production dependencies.** `VERIFY`
Two apps cannot load different versions of the same class; use `composer-bin-plugin`
for CLI tooling.

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
