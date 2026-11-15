# Marks Anywhere — Detect stamps and signatures at arbitrary positions

**Goal:** report "stamp found" / "signature found" on a page regardless of whether the mark sits on a signature line, in a margin, over text, or on a page with no signature line at all.

**Why a second plan:** the slot-classifier design (see `2026-11-15_153400-ocrflow-yolo-marks.md`) only works when OCR finds an anchor — `Подпись`, `М.П.`, a printed name line. This plan covers the general case: find the marks first, then decide what they are.

**Architecture:** two layers over the same classifier.

- **Layer 1 — anchored.** Vision gives per-page word boxes (`OcrBlock.bbox`, already computed in `worker/lib/nc_ocr_flow/ocr_models.py`). Anchors = word boxes matching `подпись|подписи|подписал|М\.П\.|печать|signature|signed` plus the printed-name line. Crop the slot, classify presence. High precision.
- **Layer 2 — swept.** Position-free proposals from colour, geometry and ink, then the same classifier on each crop. Lower precision, always shown with the crop.

**Tech stack:** PyMuPDF raster at 200 dpi; OpenCV (HLS + morphology + Hough) for proposals; onnxruntime for a small ResNet18+spatial-attention classifier (128×128 grey, ~1 MB); PHP migration + mapper; Vue chips and overlay.

**Evidence base:**
- SmartEngines (RU OCR vendor, Oct 2025): ResNet18 + spatial attention on 128×128 crops — precision 95.4 %, recall 96.4 %, **FPR 0.46 %** on 800 positive / 8,000 negative crops; attention cut false positives from 79 to 37. Their failure list is exactly ours: seals, security patterns, partially covered signatures.
- Directum (production since 2020): seals found with HLS colour segmentation + Hough circles + polar unwrap, no neural net — position-independent by construction, fails on greyscale copies.
- MDPI *Applied Sciences* 15(6):3154: YOLO stamp detection at 98.7 % mAP on 732 images — the ceiling if we ever want boxes instead of proposals.
- ABBYY FineReader has no signature or stamp check; their only stamp feature removes stamps before OCR.

**Non-goals:** authenticity/forgery, signature-to-person matching, cryptographic e-signature (separate deterministic check via PyMuPDF `/Sig`).

---

## Phase 0 — Measure the need (owner: Likivik, ~1 hour)

### Task 0.1: How often is there no anchor?
**Objective:** the fraction of pages where Layer 2 is the only route.

- For 500 random done pages, check whether the OCR text contains an anchor (`подпись|М.П.|печать`) or a printed-name line (`/ Фамилия И.О.`).
- For a 40-page sample of the *no-anchor* pages, raster and check by eye whether any stamp or signature is present.

**Done when:** a number — "X % of pages have no anchor; of those, Y % carry a mark". If Y is under ~2 %, Layer 2 ships as a review-only feature or not at all.

---

## Phase 1 — Proposals (worker)

### Task 1.1: Stamp proposals by colour
**Files:** create `worker/lib/nc_ocr_flow/marks/proposals.py`
- Load page at 200 dpi → BGR → HLS. Mask saturated pixels (S ≥ 60) in hue bands for blue/violet (150–195 in OpenCV H) and red (0–15, 165–180).
- Morphological close (5×5), connected components; keep components with area ≥ 0.15 % of the page and bounding-box aspect between 0.6 and 1.7.
- Score = mask coverage inside the component × circularity (4πA/P²). Keep the top `NC_OCR_MARKS_MAX_PROPOSALS` (default 12).

**Test:** `worker/tests/marks/test_proposals_stamp.py` — a synthetic blue circle on white yields one proposal; a blue table header (wide rectangle) yields none.

### Task 1.2: Greyscale fallback
**Files:** same module.
- If saturation coverage is under 0.05 %, the page is a greyscale copy: run `HoughCircles` on edges for radius 1.5–8 % of page width, and keep circles whose interior edge density is above a threshold (rings have edges inside).

**Test:** a black ring on white yields one proposal; a photo of a face does not (edge-density gate).

### Task 1.3: Ink proposals for signatures
**Files:** same module.
- Build a text mask from `OcrBlock.bbox` of the same page; dilate by 4 px.
- Ink = dark pixels (V ≤ 120) outside the text mask.
- Connected components; keep area 0.05–2 % of the page, height ≥ 25 px, and aspect ≤ 8. Merge components whose boxes overlap.
- Rank by ink density; cap with the same `MAX_PROPOSALS`.

**Test:** a synthetic diagonal stroke outside any text box yields one proposal; body text yields none (masked); a table rule yields none (height/area gate).

### Task 1.4: Crop and normalise
**Files:** same module.
- Expand each proposal by 8 %, clamp to the page, crop, convert to greyscale, resize to 128×128. Return `Proposal{page, kind, box, score, crop}`.

**Test:** boxes degraded at page edges are clamped, not raised.

---

## Phase 2 — Classifier

### Task 2.1: Training data from our own proposals
**Files:** create `tools/marks/collect_crops.py`
- Run proposals over the labelled documents; export every crop as `pos/` or `neg/` per annotator decision, two kinds.
- **The classifier must train on proposal crops, not on hand-drawn ones** — the distribution at inference is what the proposal stage produces.
- Hard negatives to include deliberately: logos on letterheads, blue table headers, decorative frames, photocopy noise, printed signature images, handwriting dates, initials, page numbers.
- Seed pretraining with `hvpu/document-signature-stamp-dataset` (synthetic, gated, 10k–100k pages with pasted stamps and signatures) so the head starts warm.

**Goal:** ≥ 3,000 crops per kind, ≥ 70 % negatives.

### Task 2.2: Train the classifier
**Files:** create `tools/marks/train_classifier.py`
- ResNet18-lite + spatial attention, 128×128 grey, two independent heads (stamp / signature) with sigmoid outputs.
- Report per-head precision/recall/F1/FPR on the held-out split; `model.json` records sha256, crops seen, and the four metrics.

**Gate:** stamp F1 ≥ 0.95 and FPR ≤ 0.5 %; signature F1 ≥ 0.92 and FPR ≤ 0.8 %. Below that, do not ship the swept layer.

### Task 2.3: ONNX export
**Files:** create `tools/marks/export_classifier.py`
- Opset 17, input `[N,1,128,128]` float32 normalised, outputs `stamp` and `signature` as `[N,1]`.
- Batch of 12 crops must infer in ≤ 30 ms on poweredge's CPU.

---

## Phase 3 — Policy: what a detection is allowed to claim

**Files:** create `worker/lib/nc_ocr_flow/marks/policy.py`

| Source | Condition | Reported as |
|---|---|---|
| anchor | conf ≥ 0.50 | `stamp` / `signature`, `source=anchor` |
| sweep | conf ≥ 0.90 and crop passes sanity (inside page, area) | `stamp` / `signature`, `source=sweep` |
| sweep | 0.60 ≤ conf < 0.90 | `uncertain` — admin only, never the owner chip |
| neither | — | `not_checked` when the page was never swept |

**Page-level budget:** expected false alarms per page = proposals × FPR. At 12 proposals and 0.46 % this is 5.5 % — acceptable only because sweep rows carry a crop and are phrased as "mark found, review it". Anchored rows are what the UI may state plainly.

**Wording test:** `worker/tests/marks/test_policy_wording.py` — the owner-facing strings may not contain `verified|authentic|valid|signed by`.

---

## Phase 4 — Worker integration

### Task 4.1: Façade
**Files:** create `worker/lib/nc_ocr_flow/marks/__init__.py`
- `find_marks(pdf_path, page_blocks, model_path) -> MarksReport{page, kind, source, confidence, box, crop_sha256}`.
- Feature off when `NC_OCR_MARKS_MODEL` is unset: no raster, no proposals, `not_checked`.
- Caps: `NC_OCR_MARKS_MAX_PAGES` (default 40), `MAX_PROPOSALS` (12), wall-clock guard 2 s/page.

### Task 4.2: Crops to the app
**Files:** modify `worker/lib/nc_ocr_flow/webhook_server.py`
- Crops are posted as `multipart` alongside the result (PNG, ≤ 40 KB each) or written to the app's `data/ocrflow/marks/<file_id>/<sha>.png` upload endpoint. **Decision needed:** posting crops through the existing result call keeps one code path; a separate upload endpoint keeps the result JSON small.

### Task 4.3: Non-fatal, always
**Test:** `worker/tests/marks/test_marks_never_block.py` — a model that raises, a corrupt PNG, and a proposal stage that OOMs each leave the OCR result `done`.

---

## Phase 5 — App

### Task 5.1: Migration `Version001011Date20261121120000.php`
- `oc_ocrflow_marks`: `id`, `file_id`, `etag`, `page`, `kind`, `source` (`anchor|sweep|uncertain`), `confidence` (3,3), `box` (json), `crop` (string 64, sha), `model_version`, `created_at`. Index `(file_id, etag)`.
- `oc_ocrflow_mark_crops`: `sha`, `file_id`, `bytes` (blob) — or a filesystem path with the app owning lifecycle. Blob under 40 KB is simpler and survives backup.

### Task 5.2: Mapper and validation
- `lib/Db/Marks.php`, `lib/Service/Marks.php`; `kind ∈ {stamp, signature}`, `source ∈ {anchor, sweep, uncertain}`, reject unknown, replace-by-etag in one transaction.

### Task 5.3: API
- `/mine` rows gain `marks: {stamp: n, signature: n, uncertain: n}`.
- `GET /api/v1/marks/{fileId}` returns every mark with box, page and a crop URL for the viewer overlay.

### Task 5.4: UI
- Chips: **Stamp** / **Signature** / **Stamp + signature** (anchored or high-confidence sweep only).
- Uncertain sweep rows appear only in the admin debug table, with the crop.
- Viewer overlay draws boxes on the page; clicking a box shows the crop.
- No chip is ever shown for `not_checked`; absence of a chip means "nothing found", not "verified unsigned".

**Test:** `tests/Unit/Controller/MineMarksTest.php`; wording test in Task 3.

---

## Phase 6 — Evaluation harness

**Files:** create `tools/marks/evaluate.py`, `tests/marks/hard_negatives/`

- **Page-level metrics, not only crop-level:** on 200 held-out pages, count pages where we claim a mark that is not there (false-alarm rate) and pages where a real mark is missed.
- **Hard-negative page set (≥ 30 pages):** letterheads with logos, blue table headers, decorative frames, arms/emblems, photocopied seals that are only a ring, blue handwriting notes, initials in margins.
- **Gates before enabling:**
  - stamp: page precision ≥ 0.98, recall ≥ 0.85
  - signature: page precision ≥ 0.95, recall ≥ 0.70
  - page-level false-alarm rate ≤ 2 % on the hard-negative set
- Report per source (`anchor` / `sweep`) so we can see which layer carries the accuracy.

---

## Performance budget

| Stage | Cost per page (poweredge CPU) |
|---|---|
| raster 200 dpi | ~40 ms |
| stamp proposals (HLS + components) | ~25 ms |
| ink proposals (text mask + components) | ~35 ms |
| classifier, 12 crops batched | ~20–30 ms |
| **total** | **~120–150 ms** |

Bounded by `MAX_PAGES=40` → ≤ 6 s per document worst case. Gating: skip sweep entirely on pages whose only content is a single text column and whose ink coverage is under 0.1 %.

## Risks

1. **Greyscale photocopies** — colour proposals find nothing; Hough fallback is weaker. Expect lower recall there; report it rather than hide it.
2. **Rotated or elliptical seals** — Hough handles rotation, elliptical seals partly; circularity gate may reject real oval seals. Use ellipse fit as a second scoring path.
3. **Stamps overlapping signatures** — the case the zero-shot YOLO model failed. Colour proposals find the seal; the classifier must be trained on overlapping crops explicitly.
4. **False "signed"** — the one error we cannot afford. Mitigation: sweep rows are `uncertain` under 0.9, chips carry crops, wording never asserts authenticity.
5. **Proposal distribution drift** — if the classifier is trained on hand-labelled boxes instead of proposal crops, accuracy collapses in production. Task 2.1 is the load-bearing rule.
6. **Cost** — 150 ms/page on 6,900 pages/month is ~17 min of CPU total. Not a problem; the annotation hours are the real cost.
7. **Licences** — ResNet18 weights we train ourselves; hvpu dataset is gated (needs terms), Roboflow datasets need an account; no AGPL obligation if we never ship Ultralytics code.

## Open questions

1. Crops in the DB as blobs, or on disk with a path? (Task 4.2/5.1)
2. Do we want the sweep on every page, or only on pages with no anchor and a detected mark candidate?
3. Owner-facing wording for a swept hit: "Mark found — review" or a neutral icon with no words?
4. Does the chip need to distinguish "stamp" from "signature" at all, or is "has marks" enough for your workflow?
