# Worker slots — several jobs per worker process

**Goal:** one systemd unit, N concurrent OCR jobs, controllable without a rebuild.

**Why this shape:** measured memory is a *high-water mark*, not per-job growth. A slot holds ~190 MB of interpreter, libraries and both ONNX models (8 MB), plus ~400 MB of PyMuPDF arena from the largest document it has seen — and that arena is reused by every later job. So a second systemd unit buys isolation, not throughput per byte; N slots in one process buy throughput at the same cost per slot.

**Measured, on poweredge (4 cores, 7.9 GB, 3.6 GB free):**

| State | RSS |
|---|---|
| bare python + pymupdf/numpy | 155 MB |
| + onnxruntime, google client | 181 MB |
| + both ONNX models | 189 MB |
| after 18 pages rendered at 200 dpi | 417 MB (retained after close) |
| live worker during an 18 MB PDF | 605 MB |

Practical ceiling: **2 slots comfortable, 3 tight, 4+ swaps**.

---

## Design

**One process, a thread pool over `run_once()`.**

- `run_once()` is already re-entrant: all state is per-call and the stage collector is a `ContextVar` (`worker/lib/nc_ocr_flow/stage_events.py:121`), so each thread records its own stages.
- Double-claims are already impossible: `Queue::claim()` is an `UPDATE … WHERE status='queued'` guarded by the row count.
- Every claim carries its own token, so the app never confuses two in-flight jobs from one worker.

**Three things that must change:**

1. **`QueueClient` session per thread.** `requests.Session` is not thread-safe. Give the client a thread-local session (or one client instance per slot).
2. **Shutdown must release every in-flight claim.** Today one job is held at a time and `_release_claimed()` handles it; with N slots, SIGTERM must wait briefly for each thread and release each claim it holds.
3. **The main loop becomes a pool.** Replace `while: run_once()` with N daemon threads, each looping `run_once(client, process, ...)` until a `stop` event is set.

**Where N comes from:**

- Phase 1: `NC_OCR_SLOTS` env (default **2**), wired through `services.ocrflow.slots` in the NixOS module.
- Phase 2: the app serves `slots` in the settings payload; the worker re-reads it on every idle poll and grows/shrinks the pool.

**Bounds:** none. The value the operator sets is the number of slots the process starts — no clamp, no ceiling. A slot that cannot be filled simply queues inside the process; a value above what the host can hold ends in an OOM kill, which is the operator's instruction being carried out honestly.

The worker logs the value and what the host offers at startup (`slots=8, host: 4 cores, 3.6 GB available`) so a wrong number is visible in one line rather than as a mystery restart.

---

## Phase 1 — Pool in the runner

### Task 1.1: thread-local HTTP session
**Files:** `worker/lib/nc_ocr_flow/queue_client.py`
- `threading.local()` holding a `requests.Session`; `_session()` returns it, lazily built.
- Keep the existing client object shared — only the socket pool is per thread.

**Test:** `worker/tests/test_slots.py::test_each_thread_gets_its_own_session` — two threads, assert different session ids.

### Task 1.2: pool in the main loop
**Files:** `worker/lib/nc_ocr_flow/runner.py`
- `run_forever(client, process, slots, stop_event)`; N daemon threads calling `run_once` in a loop; a job that returns `False` (empty queue) sleeps `idle`.
- `--slots` CLI flag and `NC_OCR_SLOTS` env, default 2; any positive integer is accepted and used as given.

**Test:** `test_slots.py::test_n_slots_never_process_the_same_job` — fake client hands out unique ids; assert no id handled twice.

### Task 1.3: shutdown releases all claims
**Files:** `runner.py` (`_install_signal_handlers`, `_release_claimed`)
- Registry of in-flight claims (thread → claim token); on SIGTERM set the stop event, join with a 20 s budget, release every claim still held.

**Test:** `test_slots.py::test_sigterm_releases_every_claim` — three slow jobs, send the stop event, assert three releases.

### Task 1.4: NixOS option
**Files:** `nix/module.nix` (option + environment), `modules/aspects/server/workflow-ocr/default.nix` (set the value)
- `services.ocrflow.slots = mkOption { type = ints.positive; default = 2; }` → `NC_OCR_SLOTS`.
- Document the memory arithmetic in the option description so the next person does not guess.

**Gate:** two slots on poweredge for one batch; RSS per process under 1.3 GB total, no duplicate processing in `oc_ocrflow_jobs`.

---

## Phase 1 — as built (merged #132, deployed 0.1.27)

The plan said threads with a global fitz lock. **That was wrong and the build went another way** — kept here so the next reader knows why:

- PyMuPDF's maintainers state threading is unsupported regardless of the GIL, and a lock around every fitz call means auditing seven modules forever with a segfault as the failure mode.
- A slot is a **process** (fork): its own MuPDF context, HTTP session, Vision client, SQLite connections. No shared mutable state to reason about.
- `NC_OCR_SLOTS` (default 2, no cap) via `services.ocrflow.slots`; `slots=1` keeps the old in-process loop, no fork.
- `SlotSupervisor`: dead slot → exit 1 (systemd restarts); SIGTERM → request stop, join 20 s, then terminate.
- Classifier `_MOBILENET_SESSION` lazy init locked, `prewarm()` at slot start.
- 11 tests in `worker/tests/test_slots.py`.

**Defect found in Phase 1 review:** `start_vision_quota_sampler()` ran in the parent *before* the fork. Forking a process with a live thread is the classic way to inherit a held lock, and the parent did not need the sampler — each child starts its own. Fixed: sampler starts only on the serial path.

---

## Phase 2 — live resize, per-process identity

### Task 2.1: identity `host:pid:nonce6`

Today a slot is named `worker-1` and a restart reuses the name. The registry already keeps them apart (`WorkerRegistry::register()` always inserts, so every start gets a fresh row id, and claims key on the id) — but the panel and the log show two different lives under one label.

Adopted format: **`poweredge:809912:3f9a1c`** — `socket.gethostname()`, `os.getpid()`, `secrets.token_hex(3)`.

Why this shape, from the field's own conventions:

- **Sidekiq** (`lib/sidekiq/component.rb:74`): `identity = "#{hostname}:#{Process.pid}:#{process_nonce}"` — pid is reusable, so a per-boot nonce disambiguates it.
- **OpenTelemetry** `service.instance.id`: *"MUST be unique for each instance of the same service.namespace,service.name pair"* — identity is a field separate from the human-readable name, example value a UUID.
- **No timestamp in the identity.** `started_at` is already a registry column; encoding clocks invites skew duplicates and tells you nothing the column doesn't.
- **No UUID appended on top.** The nonce exists only to make the tuple unique; a UUID would make it redundant and add 36 unreadable characters to a UI column.

Collision safety: a collision needs the same host *and* the same pid value reused *and* the same nonce — and even then it is harmless, because ids, not names, key the claims. 6 hex = 16.7 M per pid; the real risk is a confusing log line.

- `slots.slot_identity(base, slot)` returns `f"{host}:{pid}:{nonce}"`; length guard ≤ 64 (the app's column).
- Log line at start: `slot 2 starting as poweredge:809912:3f9a1c`.
- A retiring slot's identity is never reused: every new slot generates a fresh nonce.

**Tests:** two identities from two processes differ; identity contains this pid and host; ≤64 chars; a restart of the same slot yields a different identity.

### Task 2.2: setting

- `Settings` gains `worker_slots` (positive integer, no cap) in the existing settings payload; admin panel gets a number field with the memory note (~600 MB per slot).

### Task 2.3: live resize without redoing work

- The supervisor polls `worker_slots` while the pool is idle (same authenticated client), and resizes.
- **Grow:** fork a new slot with a fresh identity. Immediate.
- **Shrink:** set a retire flag on one slot; it finishes its current job, reports it, and exits — the stop check happens only *between* jobs, so **no claim is ever dropped and nothing is redone**.
- Never `SIGKILL` a working slot; never resize mid-job.
- A slot that dies without retiring is still reported as a dead slot (exit 1), unchanged.

**Tests:** grow 1→3 spawns two distinct identities; shrink 3→1 leaves one alive *after* the in-flight job completes and is reported once; no job handled twice across a resize.

### Task 2.4: per-batch control

- `occ ocrflow:slots <n>` writes the setting (admin door, no OCS session), so a batch can run at 3 and return to 2 afterwards.
- **Test:** the command writes the value and a second call restores it.

### Non-goals (decided)

- **Raising the shutdown budget** — rejected: it buys redo-free deploys only, and deploys are rare.
- Lazy grow-on-queue-depth: parked; the number is the operator's.
- Restart-free deploys.

### Risks

1. **Fork safety.** New slots are forked from a parent that must hold no threads and no open documents. The parent runs no jobs; the Phase 1 sampler defect showed how easily a thread creeps in — a test asserts the parent's thread count is 1 (`MainThread`) at fork time.
2. **Identity churn.** Frequent resizes leave dead registry rows; pruning is heartbeat-driven and already exists, so this is bounded noise in the workers list.
3. **Settings polling cost** is one authenticated GET per idle cycle — negligible against a 33 s job.


---

## Risks

1. **PyMuPDF is documented as NOT thread-safe** — one global MuPDF context, no per-thread `fz_context`; concurrent use from threads is unsupported and can crash (the opposite of what this plan's first draft assumed). Design consequence: **one global lock around every fitz call**. Acceptable because recognize (32.6 s avg) is Vision-network-bound — the parallelism comes from the wait, not the raster; expect under 2× per added slot on raster-heavy scans, near-linear on text-heavy ones. The 200-file soak proves the lock is complete (no segfaults).
2. **Per-slot clients:** each slot gets its own `vision.ImageAnnotatorClient` and `QueueClient` session (~20 MB each). ONNX `InferenceSession.run()` is documented thread-safe, but the classifier's `_MOBILENET_SESSION` lazy init (check-then-set, no lock — `classifier.py:40`) races on first concurrent use: pre-warm it behind a lock at startup, which also removes first-job latency.
3. **Memory high-water is per slot.** 3 slots after three big PDFs ≈ 1.8 GB retained, on a host with 3.6 GB free.
4. **Shutdown correctness is the sharp edge.** A claim not released on SIGTERM stays `claimed` until the heartbeat threshold requeues it — minutes of invisible stall.
5. **Quota**: Vision allows 1,800 req/min; 3 slots is nowhere near it. The real ceiling is CPU for raster (4 cores).

## Open questions

None — decided in review: default 2; pool fixed at the setting (lazy queue-depth growth parked as a Phase 3 nicety); per-slot Vision clients; PyMuPDF answered by the global lock.


---

## Thread-safety inventory (verified against the code, 2026-11-16)

**Already safe — no work needed:**

- **SQLite jobstore** (`worker/lib/nc_ocr_flow/jobstore.py:20,122`): a *fresh connection per call* with `check_same_thread=False`. The module docstring says exactly that. A job thread cannot crash on it. (An earlier draft of this plan's review feared a `check_same_thread=True` day-one crash — greped, not true.)
- **Recently-processed index** (`webhook_server.py:291-310`): `_is_recently_processed` / `_claim_node` / `_mark_processed` are **not a module-level dict** — they delegate to `jobstore.claim_node()`, the same thread-safe SQLite layer, with an atomic claim + TTL.
- **Quota gate** (`quota_gate.py:75`): already holds `self._lock = threading.Lock()`.
- **Scratch files** (`webhook_server.py:1759`): `tempfile.TemporaryDirectory()` per `process()` call — per-job by construction.
- **Stage collector**: ContextVar (`stage_events.py:121`); **claims**: atomic conditional UPDATE in `Queue::claim()`; **late reports**: discarded by token (`applied: false`); **stale recovery**: worker-level heartbeat. All verified earlier.

**Gaps this plan must still close (unchanged):**

1. `requests.Session` shared in `QueueClient` — thread-local sessions (Task 1.1).
2. Shutdown releases **every** in-flight claim, not one (Task 1.3).
3. Pool replaces the serial `while` loop (Task 1.2).

**Tests to add, pinning what is already true:**

- `test_jobstore_survives_concurrent_records` — two threads calling `record_update`; no `ProgrammingError`, no lost rows. Pins the fresh-connection-per-call property.
- `test_scratch_paths_are_per_job` — two concurrent `process()` calls, distinct scratch roots. Pins the `TemporaryDirectory` behaviour so a future refactor cannot silently break it.
- `test_recently_processed_is_atomically_claimed` — two threads claim the same node_id; exactly one wins.

The soak gate (200 real files, 3 slots vs 1 slot, identical per-file `status/pages/chars`, zero `error_worker_unclassified`, no duplicate `done` per `file_id+etag`) remains the merge blocker.

---

## Verified extras (2026-11-16, post-review)

- **Heartbeat is already a dedicated daemon thread** (`runner.py:281-318`, `start_heartbeat`): busy slots cannot starve it, so the stale-claim reaper cannot steal an in-flight claim while all slots are busy. The sharpest suspected "jobs fighting each other" race does not exist. Build-time check: re-derive the staleness threshold against worst-case contended job time.
- **Deployment timing:** the 1,610-file batch is mid-flight. A slots deploy restarts the worker, drops one in-flight claim, and relies on the heartbeat threshold to requeue it — one job redone, acceptable. Build now, deploy after the batch drains.
- **Extra tests:** `test_finish_requires_claiming_token` — a second `finish()` with a stale token must be refused (the last defence against duplicate `done` rows); soak gate also asserts no two terminal rows per `(file_id, etag)` and reconciled `times_enqueued`.
