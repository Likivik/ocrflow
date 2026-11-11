# Lessons learned building ocrflow

Not a checklist — a record. Each rule below was paid for by a real failure in this app,
and most would have been caught by a procedure rather than by reading more code.
The verification procedures live in [conformance-checklist.md](conformance-checklist.md).

Recorded because each one cost real time, and most would have been caught by a procedure
rather than by reading more code.

1. **A migration without a version bump never runs.** Files ship; the database does not
   change until `info.xml` says there is something to apply. It looks deployed and it is
   not — verify by querying the schema, never by the deploy log.

2. **`occ upgrade` is runtime state, not imperative config.** Nix ships files, Postgres
   holds schema versions, and the NixOS module deliberately never upgrades the database
   (`autoUpdateApps` defaults to false and covers app-store apps only). Declarative
   infrastructure does not remove this step.

3. **A dedupe key can hide an entire branch.** The empty-source decision sat *after* an
   early return that matched an existing row for `(file_id, etag)`; the branch was dead
   for days and produced no symptom other than "no rows". Put cheap terminal decisions
   first, before any lookup that can return early.

4. **An exception must never make a product decision.** A 422 raised as a protocol error
   was classified `app_5xx` and retried forever. Classify by HTTP status into a closed
   vocabulary: 422 terminal, 404 gone, 401/403 credentials, 5xx retryable.

5. **Two recorders is how decisions get lost.** Every decision — app-side or worker-side —
   writes one row through one insert path, deduped on `(job_id, stage, reason_code)`.

6. **Tolerant boolean parsing picks the destructive branch.** `NC_OCR_KEEP_SOURCE_IMAGE=off`
   *kept* the user's image, because the falsy list forgot `off`. Values set by a machine
   (Nix `toString` gives `"1"`/`""`) should be strict and fail at boot; tolerance belongs
   where humans type.

7. **Name a setting after the property, not the exception.** A default-on switch called
   `*_disabled` forces a negation into every layer — method, API payload, UI property —
   and the reader has to invert it correctly each time.

8. **Never delete user data when trash will do.** A WebDAV `DELETE` from the worker
   bypassed Nextcloud's trash entirely; a filesystem-layer delete (`$node->delete()`)
   goes to it. Verify by querying `oc_files_trash` — and note the column is `location`,
   not `name`.

9. **A fake that answers "nothing found" to every query is a false green.** The dedupe
   test passed against a stub that made the code path unreachable.

10. **A negative result from a grep proves nothing until the query is known-good.** A
    wrong column name returns zero rows and reads as "clean"; a `2>/dev/null` hides the
    traceback that would have said so. Both happened in one audit, and both produced
    confident wrong conclusions.

11. **Cohort averages hide the defect; paired comparisons expose it.** Dictionary hit
    rates said the old engine era was fine (~70% everywhere); comparing two text layers
    of the *same scan* showed a median 60% token overlap with the born-digital original
    and a mass at 50-70%. The control was the same document, not the same cohort.

12. **Merge state is a fact to read, never to remember.** Four "it is merged" claims in
    this project came from summaries rather than `gh pr view`; all four were wrong.

13. **A delegation brief must pre-decide its vocabulary.** A brief that left the stage
    taxonomy open burned 250 tool calls and produced zero code; the same task with seven
    stages named finished correctly.

14. **A brief that does not open with `ws_doctor` + `cd` lets the child work in the
    parent's tree.** The gate binds a child to its own workspace, but its shell still
    starts in the parent's directory; without the redirect the child runs `jj` there, and
    a `jj new main` silently deletes untracked files that live only on disk. Both trees
    looked correct from the outside, so the loss surfaced as "the file is gone".

15. **Claims of version support need a run, not a declaration.** `max-version="35"` on an
    instance running 34 is an opinion until CI runs against 35.

16. **Verify the isolation before blaming it.** After that file vanished, the obvious
    conclusion was "the child shared my workspace". `ws_status` showed the opposite: the
    parent was correctly bound and four child workspaces existed. The shared `jj op log`
    then showed the child's commands attributed to `container-first@` — the parent's tree.
    Two tools, two answers, and only the second one was true.
