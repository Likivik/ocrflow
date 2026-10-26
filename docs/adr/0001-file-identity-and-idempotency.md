# ADR 0002 — File identity, content version, and doing the work once

- **Status:** accepted
- **Date:** 2026-10-26
- **Supersedes:** the worker-local SQLite "already OCR'd" index as a de-duplication mechanism

## Problem

"Do not OCR the same file twice" used to be a *check*: before enqueueing, the
worker asked a local SQLite index whether this path was already done. That
index lives on a container volume, is invisible to the Nextcloud instance, and
degrades to a silent no-op whenever it cannot be opened — at which point every
file looks unprocessed and gets OCR'd again, at real API cost.

It was also keyed on the wrong thing. A path is not identity: renaming or moving
a file changes it, and Nextcloud can deliver the same event more than once.
Both caused avoidable re-runs, and the reverse case — a file whose *content*
changed but whose path did not — was indistinguishable from an untouched file.

## Decision

Identity of a unit of work is **`(file_id, etag)`**.

- `file_id` is Nextcloud's own row id for the file. It survives rename and move,
  which is why a moved file is not re-OCR'd.
- `etag` is Nextcloud's content version. It changes when the bytes change, which
  is the only thing that justifies OCR'ing again.
- The path is a **label for humans and for DAV**, never a key.

Enforcement lives in the database schema, so a duplicate is impossible rather
than merely unlikely:

| Table | Constraint | Meaning |
| --- | --- | --- |
| `ocrflow_jobs` | `UNIQUE (file_id, etag)` | at most one job per content version ever |
| `ocrflow_files` | `UNIQUE (file_id)` | one current state row per file, whatever its path |

`Queue::enqueue()` returns `null` — "nothing to do" — in three cases, and only
three: a job already exists for this version, the file's state row says this
version is already `done`, or the insert lost a race and the database rejected
it with a unique-constraint violation. `null` is not an error; callers count it
as skipped.

## Consequences

- A second delivery of the same event costs one indexed `SELECT` and nothing
  else. No OCR call, no API spend.
- Rename and move are free. Re-OCR happens on content change, which is the
  semantics an operator expects.
- Wanting to re-run anyway (a better engine, a bad first result) needs an
  explicit lever, and it must be visible in the queue: a forced re-OCR writes a
  job whose `etag` is suffixed with the reason (`<etag>#force`), so the unique
  index still protects the normal path and the forced job is identifiable.
- `file_id` may be `NULL` for a path that has no cache row. `UNIQUE` does not
  apply between `NULL`s, so those rows fall back to the weaker path rule in
  `enqueue()`. This is a known, narrow hole: such files are always enqueued, and
  the worker's own stamp check remains the last line of defence.
- The worker no longer keeps a local index of what it has processed. It claims,
  works, reports. Its `skip_ocrd` path is retired when the OCS queue client
  lands.
- The XMP stamp inside the produced PDF is unchanged and still the only marker
  that survives leaving the instance: a file copied out and back in is a *new*
  `file_id`, and the stamp is what keeps it from being re-processed.

## Alternatives rejected

- **Path as key** (the previous rule): wrong under rename and move, and does not
  distinguish a content change at the same path.
- **Checksum as key**: correct but expensive — hashing a 40 MB scan on every event
  to answer a question the etag already answers upstream.
- **Worker-local SQLite index**: dies with the volume, is invisible to
  `occ maintenance:backup`, and fails silently. That failure mode is the reason
  this ADR exists.

## Verification

The constraint is tested where it is enforced: two concurrent enqueues of the
same `(file_id, etag)` yield one job; the same file with a new `etag` yields a
second; the same content at a new path yields none. Until the PHPUnit job exists
in CI, that test runs from the app's `tests/Unit/Db/QueueIdempotencyTest.php`
suite locally, and the migration is exercised against a real schema.
