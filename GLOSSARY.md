# Glossary

**Run** — one attempt to OCR one file version. A run owns a claim, a spend
booking and a `files_lock` identity. It ends by settling, failing, or being
recovered.

**Claim** — the right to work on a queued run. A claim is identified by a
random token and a revision. The token says *which claim*; the revision says
*which generation of that claim*.

**Claim revision** — incremented on every successful claim and renewal. Recovery
and settlement must name the exact revision they judged stale, so a renewed
claim can never be requeued or settled from an old snapshot.

**Observation** — the latest known path, etag and size for a file id that still
needs work. It is stored before any queue decision, so a crash cannot lose an
upload. Repeating an observation increments its revision.

**Drain** — turning one durable observation into queued work. A drain is safe
to repeat: it re-reads the observation, resolves the file's current node, and
deletes only the observation revision it consumed.

**Supersession** — deleting an obsolete *queued* run for a file when newer work
arrives. It never touches a claimed run; the claimed run settles, then the
durable observation becomes the next run.

**Stash** — a finished OCR result whose source bytes changed during the run.
The bytes are kept, the row is `pending`, and a human chooses Apply or Discard.

**Decision lock** — the per-stash exclusive lock shared by Apply, Discard and
expiry. It permits exactly one decision per artifact; a competing caller is
refused without touching file or row.

**Settlement** — the atomic close of a run: status, ledger facts, artifact
identity and spend are written together. A settled run is never settled twice.

**Reconcile** — the periodic sweep. It drains durable observations, requeues
expired claims with exact ownership fences, settles doomed ceiling runs, and
releases bookings and locks left behind by crashes.
