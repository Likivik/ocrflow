# Stamp & Signature Marks — Implementation Plan

**Goal:** every processed page is checked for stamps and signatures, and the result lands in the app as *evidence* (page, box, confidence, model version) — never as a legal claim like "signed".

**Architecture:** one small YOLO detector (ONNX, two classes: `stamp`, `signature`) runs in the existing worker with the `onnxruntime` dependency we already ship. The worker reports marks in the result payload next to `pages`/`chars`; the app stores them in a new table and shows three-state chips in the personal and admin panels.

**Tech stack:** Ultralytics YOLO (AGPL-3.0 — compatible with our licence) → ONNX → onnxruntime CPU; PyMuPDF for page raster; PHP migration + mapper + service; Vue panel.

**Non-goals for v1:** crop thumbnails, forgery/verification (is this stamp *genuine*), signature-to-person matching, e-signature cryptography (that is a separate, deterministic PDF-signature check).

---

## Phase 0 — Measure before building (owner: Likivik, no code)

The published numbers are on invoices and US forms. The point of this phase is to learn whether they survive Russian scans.

### Task 0.1: Pick the sample
**Objective:** 120 documents, 1–3 pages each, from the folders that matter.
- Query production for candidates: last-modified, page count already known from `oc_ocrflow_jobs.pages`.
- Take 60 from `Work/1-Аренда/…` and 60 from `Work/1-Diosa/…`, spread across months, mix of scans and born-digital.
- Export the PDFs to a local working dir; do not upload them anywhere.

**Done when:** 120 PDFs on disk and a `manifest.tsv` (path, pages, etag).

### Task 0.2: Annotate
**Objective:** boxes for `stamp` and `signature`, nothing else.
- Tool: CVAT or Label Studio, **run locally** (no Roboflow — it uploads the documents).
- Two classes only. One box per object. Overlapping stamp+signature = two boxes.
- Expected effort: 20–30 s/page → **8–12 hours total**.

**Done when:** every page annotated, second pass over 10 random pages to check consistency.

### Task 0.3: Freeze the split
**Objective:** a held-out set nobody trains on.
- 70 / 15 / 15 by *document*, not by page (pages of one document leak to each other).
- Store as `tests/marks/manifest.json` with sha256 per image — same pattern as `tests/corpora/manifest.json`.

**Done when:** `python tools/marks/check_split.py` reports 0 overlaps.

---

## Phase 1 — Training pipeline (repo work)

### Task 1.1: Dataset prep script
**Files:** create `tools/marks/prepare.py`, `tests/marks/README.md`
- Convert annotations to YOLO txt, rasterise pages at 200 dpi with PyMuPDF `get_pixmap(dpi=200)`.
- Fail loudly when a label has no image or a box is outside the bitmap.

**Test:** `worker/tests/test_marks_dataset.py::test_a_box_outside_the_bitmap_fails` — red first.

### Task 1.2: Train wrapper
**Files:** create `tools/marks/train.py`, `tools/marks/README.md`
- Wraps `ultralytics.YOLO("yolov9s.pt")` (or `yolov8s`), `imgsz=640`, deterministic seed, records `runs/<ts>/results.csv`.
- Writes `model.json`: `{classes, imgsz, mAP50, mAP50_95, precision, recall, sha256, trained_at}`.
- **Run on a rented GPU** (~$1–3). CPU on poweredge is many hours.

**Test:** unit-test only the argument parsing and the `model.json` writer; training itself is not a test.

### Task 1.3: ONNX export + eval gate
**Files:** create `tools/marks/export.py`, `tools/marks/eval.py`
- Export with fixed `opset=17`, `simplify=True`; assert output `[1, 4+nc, N]`.
- `eval.py` runs the ONNX model (not the torch one) over the frozen split and prints:
  `stamp F1 … | signature F1 … | pages/s … | ms/page …`.
- **Gate: stamp F1 ≥ 0.90, signature F1 ≥ 0.85, ≥ 95 % of a 30-page sample visually correct.** Below that, stop — do not ship.

**Done when:** `model.json` and the eval report are committed under `docs/marks/`.

---

## Phase 2 — Worker integration

### Task 2.1: Detector module
**Files:** create `worker/lib/nc_ocr_flow/marks.py`; modify `worker/lib/nc_ocr_flow/config.py`
- `detect_marks(pdf_path, model_path, min_conf) -> list[Mark]`; `Mark = {page, kind, confidence, box}`.
- Raster per page at 200 dpi, cap at `NC_OCR_MARKS_MAX_PAGES` (default 40).
- Gating: run only on (a) the last page, and (b) pages whose OCR text matches stamp/signature cues (`подпись`, `М.П.`, `печать`, `signature`, `signed`). Everything else is skipped and reported as `not_checked`.
- Boxes clamped to image bounds; `confidence` rounded to 3 decimals.

**Tests (`worker/tests/test_marks.py`, red first):**
1. a page with no cue is not run; result says `not_checked`
2. a detection maps to `{page, kind, confidence, box}` with the box inside the bitmap
3. a model file that does not exist raises `MarksUnavailable` — and the job still completes
4. more than `NC_OCR_MARKS_MAX_PAGES` pages → the rest reported skipped, no crash

### Task 2.2: Wire it, non-fatally
**Files:** modify `worker/lib/nc_ocr_flow/webhook_server.py` (after the OCR result is assembled, before `_report`)
- `marks` is an optional key in the payload; any exception logs and sends `marks_error`, never fails the job.
- Stage row `stage="marks"` with duration, so cost is visible in `oc_ocrflow_stage_events`.

**Test:** `test_a_broken_marker_never_fails_the_job` — asserts the report still carries `status=done`.

---

## Phase 3 — App: store and expose

### Task 3.1: Migration
**Files:** create `lib/Migration/Version001010Date20261120120000.php`
- Table `oc_ocrflow_marks`: `id`, `file_id` (int), `etag` (string 64), `page` (int), `kind` (string 16), `confidence` (decimal 3,3), `box` (json), `model_version` (string 64), `created_at` (int). Unique `(file_id, etag, page, kind, box_hash)`; index `(file_id)`.
- Down-migration drops the table.

**Test:** `tests/Unit/Db/MarksSchemaTest.php` — every column in the mapper exists in the migration (the `SchemaCodeDriftTest` pattern).

### Task 3.2: Mapper + service
**Files:** create `lib/Db/Marks.php`, `lib/Service/Marks.php`
- `Marks::replaceForVersion(fileId, etag, marks)` — delete-then-insert in one transaction.
- Validation: `kind ∈ {stamp, signature}`; `0 ≤ confidence ≤ 1`; `page ≥ 0`; unknown kind is dropped **and logged**, never stored.

**Tests (red first):** unknown kind rejected; confidence 1.7 rejected; a second report for the same etag replaces, not duplicates.

### Task 3.3: Report path
**Files:** modify `lib/Controller/WorkerController.php` (where `pages`/`chars` are accepted)
- `marks` is optional; absent = "not checked" (no rows). Present = replace for the version.

### Task 3.4: Panel chips
**Files:** modify `src/Panel.vue`, `src/AdminPanel.vue`, `lib/Service/Mine.php`
- `/mine` rows gain `marks: {stamp: n, signature: n}`.
- Chip text: **"Stamp"**, **"Signed"**, **"Stamp + signed"**; nothing when `n = 0` for both; `not checked` shown only in the admin hover, never as a claim of absence.
- Never the word "verified" or "authentic".

**Test:** `tests/Unit/Controller/MineMarksTest.php` + a wording test that forbids `verified|authentic|valid` in mark chips.

### Task 3.5: Integration proof
**Files:** modify `tests/integration/app-smoke.sh`
- Upload a fixture PDF that carries a synthetic stamp image → assert the `/mine` row reports `stamp ≥ 1`, and that a PDF without marks reports 0.

---

## Phase 4 — Cost and safety guardrails

- **Per-page budget:** raster + ONNX at 200 dpi is ~50–150 ms/page on poweredge; with gating expect ≤ 3 pages per document.
- `NC_OCR_MARKS_MODEL` unset → the whole feature is off (no raster, no load). Default off until the gate in Task 1.3 passes.
- Model file: shipped through nix (`nix/worker-env.nix` + a pinned-hash `fetchurl`), never committed to git.
- Failure policy: marks are **evidence, never a blocker**. A detector crash must leave the OCR result intact.

---

## Files likely to change

- `worker/lib/nc_ocr_flow/marks.py` (new), `config.py`, `webhook_server.py`, `stage_events.py`
- `worker/tests/test_marks.py`, `test_marks_dataset.py` (new)
- `tools/marks/{prepare,train,export,eval,check_split}.py` (new)
- `lib/Migration/Version001010Date20261120120000.php` (new), `lib/Db/Marks.php`, `lib/Service/{Marks,Mine}.php`, `lib/Controller/{Worker,Mine}Controller.php`
- `src/Panel.vue`, `src/AdminPanel.vue`
- `tests/Unit/{Db/MarksSchemaTest,Service/MarksValidationTest,Controller/MineMarksTest}.php` (new)
- `tests/integration/app-smoke.sh`, `tests/marks/manifest.json`, `docs/marks/README.md` (new)
- `nix/worker-env.nix` (model input), `nix/package-app.nix` (nothing — no new app asset)

---

## Risks

1. **Domain gap.** StaVer/DDI-100 are invoices with colour logos; Russian round seals on your scans may score 10–20 points lower. Phase 1 gate exists to catch this before code is written around it.
2. **Signature F1.** Even at 0.93, 7 % of pages are wrong. Mitigation: three-state chips + confidence + boxes visible in the admin plane; never "signed" as a guarantee.
3. **Annotation time.** 8–12 h of your hands. This is the real cost of the feature.
4. **CPU cost on poweredge** if gating fails and every page is rasterised: 40 pages × 120 ms ≈ 5 s per document. Cap in Task 2.1.
5. **Licence.** Ultralytics AGPL-3.0 is fine while ocrflow is AGPL. If any part ever goes closed, this becomes an Enterprise-licence item.
6. **Numbers from other people's datasets** are a ceiling, not a promise. Only Task 1.3 measures your corpus.

## Open questions

1. Which document types actually need this — contracts only, or everything in the rentals folder?
2. Is "stamp present" useful to you on its own, or only together with a signature?
3. Do you want the boxes visible in the file viewer in v1, or chips only?
4. Threshold policy: at what confidence should a mark be shown at all (0.35 draft / 0.6 conservative)?
