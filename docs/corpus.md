# The PDF corpus canary

A hash-pinned set of 54 real damaged PDFs, measured through the worker's own
classifier **in the interpreter the worker is deployed with**, on demand.

```
tests/corpora/
  manifest.json   the 54 files: set, name, bytes, sha256, url  (committed)
  fetch.sh        download + verify into cache/               (committed)
  check.py        measure with nc_ocr_flow, diff a baseline   (committed)
  baseline.json   the measured verdicts, per file             (committed)
  cache/          the 63 MB of PDFs themselves                 (git-ignored)
```

## What it is

`openpreserve/format-corpus` → `govdocs1-error-pdfs`: 54 PDFs (65,750,495 bytes)
that real extraction tools choked on — broken xrefs, truncated content streams,
font programs with unknown formats, zlib streams with invalid distances. Two
sets: `error_set_1` (21 files), `error_set_2` (33 files).

The unit suite already covers the *shape* of a damaged file: `worker/tests/fixtures/make_error_fixtures.py`
builds zero-byte, truncated and bad-xref PDFs byte by byte, and
`worker/tests/test_broken_pdf.py` pins the classifier's verdict on those exact
bytes. That proves our generator and our code agree. It cannot prove what MuPDF
does with twenty years of genuinely corrupt files — and MuPDF is a C library
behind a wheel, so a nixpkgs bump or a rebuild with another compiler can change
its behaviour with no Python change at all.

The canary fills that gap: same bytes every time (sha256-pinned), the production
code path (`nc_ocr_flow.pdf_classify.classify_pdf` and `broken_pdf_reason`),
and a report that records `pymupdf.__version__` and `sys.version` next to the
numbers, so a change is attributable.

## Why on demand, not nightly

* **It measures a dependency bump, and nothing else moves it.** Nothing in this
  repository can change the answer — the bytes are pinned, the code path is
  production's, the interpreter is `nix/worker-env.nix`. A nightly run would
  print the same numbers 365 times a year and train everyone to ignore it.
* **A nightly failure has no owner.** When a nixpkgs bump breaks the parser, the
  person who bumped it should see the failure, in a run they started, with the
  report in hand. A red scheduled job at 03:00 is a notification nobody reads.
* **It costs minutes and a gigabyte of closures** (PyMuPDF, onnxruntime, the
  Google SDKs) plus 63 MB of downloads. That is not a per-PR price, and it is
  not a background-discretionary price either.
* **The inputs are not ours.** Downloading 63 MB from GitHub on a schedule is a
  quiet way to get rate-limited; the pin means a fresh download is only needed
  when a cache is cold.

Run it when: you bump `flake.lock`, you touch `worker/lib/nc_ocr_flow/pdf_classify.py`
or `pdfio.py`, or you are about to deploy a rebuilt worker image.

## Why it is NOT a source of `error_file_broken` fixtures

Tempting, and wrong:

* **It does not contain the shape we mean.** All 54 files parse: 54 openable, 0
  broken. `broken_pdf_reason` returns `None` for every one of them. Pointing the
  `error_file_broken` tests at this corpus would assert nothing — the fixtures
  would be "real" and the assertion vacuous.
* **A fixture must be explainable.** `tests/fixtures/make_error_fixtures.py`
  builds its PDFs from a comment-documented byte layout, so a failure names the
  byte offset that broke. `error_set_2/432977.pdf` names nothing.
* **A fixture must be committed.** These files are 63 MB and git-ignored, so a
  test that depended on them would be skipped exactly where it matters — on a
  fresh clone and in CI.
* **License and privacy.** govdocs1 is public-domain US government material, but
  a real document is still a real document; the repository's rule is that user
  bytes are never committed. Generated fixtures keep that rule intact.

So: generated fixtures assert the *code*; the corpus asserts the *binary*.

## How to run it

Locally, with the same interpreter the deploy uses:

```sh
bash tests/corpora/fetch.sh                     # 54/54 verified into tests/corpora/cache
nix build .#ocrflow-worker-env -o worker-env
PYTHONPATH=worker/lib ./worker-env/bin/python tests/corpora/check.py \
    --baseline tests/corpora/baseline.json
```

Exit codes: `0` no regressions; `1` a file that parsed in the baseline no longer
parses (the only fatal outcome); `2` the corpus is incomplete or there is no
baseline — an infrastructure problem, not a parser verdict.

On GitHub: **Actions → corpus → Run workflow**. It builds
`.#ocrflow-worker-env` with Nix, fetches, runs, and uploads
`tests/corpora/cache/report.json` as the `corpus-report` artifact. There is no
schedule, on purpose.

`fetch.sh --verify-only` checks the cache against the manifest without touching
the network; `--clean` deletes it.

## What counts as a failure

Only `parsed → broken`. A changed `pdf_type`, a changed page count, or a file
new to the manifest is printed as **drift** and recorded in the report, but does
not fail the run: a dependency bump that reclassifies two scanned documents is a
thing to read, not a thing to block.

To re-baseline, delete nothing — run `check.py` without `--baseline`, or copy
`cache/report.json`'s `files` block into `baseline.json`, and commit that as its
own reviewed change with the report attached to the PR. A baseline that moves in
the same commit as a dependency bump is a baseline nobody looked at.

## Two things that will confuse you

**MuPDF noise.** Damaged PDFs make MuPDF print `MuPDF error: syntax error: …` for
every bad token in the content stream — around 180 KB on this corpus, written to
stdout through PyMuPDF's Python callback, interleaved with our own output. `check.py`
turns the printing off (`pymupdf.TOOLS.mupdf_display_errors(False)`) and records
the *count* per file as `mupdf_warnings` instead, which is the useful signal. A
warning count that jumps while `openable` stays true is a parser change worth
reading. It also had to make stdout surrogate-safe: some messages carry bytes
that are not valid UTF-8, and the first version of this script died with a
`UnicodeEncodeError` raised *inside the C callback*, before writing any report.

**The classifier has two paths, and the Nix environment takes the second one.**
`classify_pdf` prefers the `pdf-inspector` package and falls back to a PyMuPDF
heuristic when it is absent. The Nix worker environment (`nix/worker-env.nix`)
does not include `pdf-inspector`, so the canary measures the heuristic — the
same code production runs. That is why the baseline in this repository says
**32 `text_based` / 22 needs-OCR**, while the same corpus measured with
`pdf-inspector` installed reports **46 / 15**. Both are recorded truth for their
own environment; do not reconcile them by editing the baseline. If the
deployment ever gains `pdf-inspector`, add it to `nix/worker-env.nix` and
re-baseline in one commit, with the two reports side by side.
