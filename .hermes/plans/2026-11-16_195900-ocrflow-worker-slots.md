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

## Phase 2 — app-controlled slots (the UI knob)

### Task 2.1: setting
- `Settings` gains `worker_slots` (any positive integer — no cap), served in the existing settings payload; admin panel gets a number field with the memory note.

### Task 2.2: worker re-reads on idle
- After an empty poll, the worker fetches the setting (the same authenticated client) and resizes the pool: start threads to grow, set a per-thread retire flag to shrink. Never kill a thread mid-job.

**Test:** `test_slots.py::test_pool_grows_and_shrinks_on_setting` — fake settings source returns 1, then 3, then 1; assert thread count follows without dropping work.

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
