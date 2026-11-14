#!/usr/bin/env python3
"""Measure the corpus with the worker's *own* code and diff it against a baseline.

Why this exists
---------------
MuPDF is a C library behind a Python wheel. A nixpkgs bump, a PyPI bump or a
rebuild with a different compiler can change what it does to a damaged PDF —
silently. The unit suite builds its damaged fixtures byte by byte, so it proves
what our *generator* produces, not what MuPDF does with 20 years of real broken
files. This script runs the production path (`nc_ocr_flow.pdf_classify`) over a
hash-pinned corpus of real govdocs1 PDFs and records the verdict per file.

What it fails on — and what it deliberately does not
----------------------------------------------------
It fails when a file that previously parsed stops parsing: `broken_pdf_reason`
returns a reason (or pymupdf refuses to open the document) for a file the
baseline recorded as openable. That is the regression that reaches users as
"the worker silently skips my contract" or as a retry storm of 400s.

It does NOT fail on a changed `pdf_type` or a changed text length, and it does
NOT fail on a new file appearing in the manifest: those are drift worth reading
in the artifact, not a reason to block a dependency bump. Everything is recorded
in the JSON so a human can see the drift; only "parsed → broken" is fatal.

Run it with the interpreter the worker is deployed with:

    PYTHONPATH=worker/lib ./worker-env/bin/python tests/corpora/check.py \\
        --baseline tests/corpora/baseline.json

`tests/corpora/fetch.sh` must have run first (or pass --cache).
"""

from __future__ import annotations

import argparse
import json
import sys
import time
import traceback
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent

# Recorded in the report so the artifact answers "which parser measured this?"
# without a second grep of the log. The canary is worthless without it: the
# whole point is that a version change is visible next to the numbers.
try:
    import pymupdf  # type: ignore[import-not-found]
except ImportError as exc:  # pragma: no cover - a broken env, not a corpus fact
    print(f"FATAL: pymupdf is not importable from {sys.executable}: {exc}", file=sys.stderr)
    raise SystemExit(2)

# MuPDF writes its complaints through a Python callback that prints to stdout, one
# line per syntax error in a damaged file. On this corpus that is ~180 KB of
# interleaved noise, and it includes raw bytes that are not valid UTF-8 — which
# once killed the run with UnicodeEncodeError from inside the C callback rather
# than from our own code. So: turn the per-error printing off, keep the counts
# (they are measured per file below), and make the streams surrogate-safe so a
# hostile filename or message can never abort the report.
if hasattr(pymupdf, "TOOLS"):
    pymupdf.TOOLS.mupdf_display_errors(False)
    pymupdf.TOOLS.mupdf_display_warnings(False)
for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(errors="replace")  # type: ignore[attr-defined]
    except (AttributeError, ValueError):  # pragma: no cover - older/odd streams
        pass


try:
    from nc_ocr_flow.pdf_classify import broken_pdf_reason, classify_pdf
except ImportError as exc:  # pragma: no cover
    print(
        f"FATAL: cannot import nc_ocr_flow from {sys.executable} ({exc}).\n"
        "Run with PYTHONPATH=worker/lib.",
        file=sys.stderr,
    )
    raise SystemExit(2)


def _pymupdf_version() -> str:
    # pymupdf renamed its version attribute across releases (fitz.__doc__ carried
    # it once); try the modern names in order rather than pinning one.
    for attr in ("__version__", "version", "VersionBind", "MuPDF_version"):
        value = getattr(pymupdf, attr, None)
        if isinstance(value, str):
            return value
    return "unknown"


def measure(path: Path) -> dict:
    """One file, through the same functions the worker calls."""
    entry: dict = {
        "openable": False,
        "pages": 0,
        "text_chars": 0,
        "pdf_type": None,
        "needs_ocr": None,
        "broken": None,
        "error": None,
    }
    started = time.monotonic()
    # Count MuPDF's complaints instead of letting them scroll past: a file that
    # parses but throws 40 syntax errors is a different fact from a clean one,
    # and the count is stable enough to notice a parser change that does not
    # flip the openable verdict.
    if hasattr(pymupdf, "TOOLS"):
        pymupdf.TOOLS.reset_mupdf_warnings()
    try:
        document = pymupdf.open(str(path))
    except Exception as exc:
        entry["broken"] = f"unopenable ({type(exc).__name__}: {exc})"
        entry["error"] = f"pymupdf.open: {type(exc).__name__}"
    else:
        try:
            entry["openable"] = True
            entry["pages"] = document.page_count
            chars = 0
            for page in document:
                chars += len(page.get_text())
            entry["text_chars"] = chars
        finally:
            document.close()

    # broken_pdf_reason is the production gate: unopenable or zero pages. Asked
    # separately from the loop above so a disagreement between "pymupdf opened
    # it for me" and "the worker says it is broken" shows up as a difference.
    reason = broken_pdf_reason(str(path))
    if reason is not None:
        entry["broken"] = reason

    try:
        verdict = classify_pdf(str(path))
        entry["pdf_type"] = verdict.get("pdf_type")
        entry["needs_ocr"] = verdict.get("needs_ocr")
    except Exception as exc:  # a classifier crash is a corpus fact worth recording
        entry["error"] = f"classify_pdf: {type(exc).__name__}: {exc}"
        entry["traceback"] = traceback.format_exc(limit=3)

    entry["seconds"] = round(time.monotonic() - started, 3)
    if hasattr(pymupdf, "TOOLS"):
        try:
            entry["mupdf_warnings"] = len(pymupdf.TOOLS.mupdf_warnings().splitlines())
        except Exception:  # pragma: no cover - API churn, not a corpus fact
            entry["mupdf_warnings"] = None
    return entry


def load_manifest(path: Path) -> list[dict]:
    return json.loads(path.read_text())["files"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--baseline", type=Path, default=HERE / "baseline.json")
    parser.add_argument("--manifest", type=Path, default=HERE / "manifest.json")
    parser.add_argument("--cache", type=Path, default=HERE / "cache")
    parser.add_argument("--report", type=Path, default=None, help="default: <cache>/report.json")
    args = parser.parse_args(argv)

    report_path = args.report or (args.cache / "report.json")
    files = load_manifest(args.manifest)

    measured: dict[str, dict] = {}
    missing: list[str] = []
    for item in files:
        key = f"{item['set']}/{item['name']}"
        path = args.cache / f"{item['set']}-{item['name']}"
        # Missing bytes are an infrastructure problem (fetch.sh did not run or
        # the cache is stale), not a parser regression — say which and stop.
        if not path.is_file():
            missing.append(key)
            continue
        try:
            measured[key] = measure(path)
        except Exception as exc:
            # Never lose the report to one pathological file: record it as a
            # failure for that file and let the baseline comparison speak.
            measured[key] = {
                "openable": False,
                "pages": 0,
                "text_chars": 0,
                "pdf_type": None,
                "needs_ocr": None,
                "broken": f"measure crashed ({type(exc).__name__}: {exc})",
                "error": f"measure: {type(exc).__name__}",
                "traceback": traceback.format_exc(limit=3),
            }

    summary = {
        "total": len(measured),
        "openable": sum(1 for v in measured.values() if v["openable"]),
        "broken": sum(1 for v in measured.values() if v["broken"]),
        "needs_ocr": sum(1 for v in measured.values() if v["needs_ocr"]),
        "text_based": sum(1 for v in measured.values() if v["pdf_type"] == "text_based"),
        "pages": sum(v["pages"] for v in measured.values()),
        "text_chars": sum(v["text_chars"] for v in measured.values()),
        "errors": sum(1 for v in measured.values() if v["error"]),
    }
    report = {
        "meta": {
            "python": sys.version.replace("\n", " "),
            "executable": sys.executable,
            "pymupdf": _pymupdf_version(),
            "pymupdf_file": getattr(pymupdf, "__file__", "unknown"),
            "nc_ocr_flow": getattr(sys.modules.get("nc_ocr_flow"), "__file__", "unknown"),
            "platform": sys.platform,
            "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "manifest_files": len(files),
            "missing_files": missing,
        },
        "summary": summary,
        "files": measured,
    }
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")

    print(f"pymupdf {report['meta']['pymupdf']}  python {report['meta']['python'].split()[0]}")
    print(
        "measured {total} files: {openable} openable, {broken} broken, "
        "{text_based} text_based, {needs_ocr} need OCR, {pages} pages".format(**summary)
    )
    print(f"report: {report_path}")

    if missing:
        print(f"\nFATAL: {len(missing)} of {len(files)} corpus files are not in {args.cache}:", file=sys.stderr)
        for key in missing[:10]:
            print(f"  {key}", file=sys.stderr)
        if len(missing) > 10:
            print(f"  ... and {len(missing) - 10} more", file=sys.stderr)
        print("run: bash tests/corpora/fetch.sh", file=sys.stderr)
        return 2

    if not args.baseline.exists():
        print(f"\nno baseline at {args.baseline}: nothing to compare (report written)", file=sys.stderr)
        return 2

    baseline = json.loads(args.baseline.read_text())
    before = baseline.get("files", {})

    regressions = []
    drift = []
    for key, now in measured.items():
        was = before.get(key)
        if was is None:
            drift.append(f"{key}: new in the manifest, no baseline (not a regression)")
            continue
        if was.get("openable") and (not now["openable"] or now["broken"]):
            regressions.append(
                f"{key}: parsed in the baseline (pages={was.get('pages')}, "
                f"text_chars={was.get('text_chars')}) but is now "
                f"{'unopenable' if not now['openable'] else 'broken'}: {now['broken']}"
            )
        elif was.get("pdf_type") != now.get("pdf_type"):
            drift.append(
                f"{key}: pdf_type {was.get('pdf_type')!r} -> {now.get('pdf_type')!r} (not fatal)"
            )
        elif was.get("pages") != now.get("pages"):
            drift.append(f"{key}: pages {was.get('pages')} -> {now.get('pages')} (not fatal)")

    gone = sorted(set(before) - set(measured))
    for key in gone:
        drift.append(f"{key}: in the baseline but not in the manifest")

    if drift:
        print(f"\ndrift ({len(drift)}, not fatal):")
        for line in drift:
            print(f"  {line}")

    if regressions:
        print(f"\nREGRESSIONS: {len(regressions)} file(s) stopped parsing", file=sys.stderr)
        for line in regressions:
            print(f"  {line}", file=sys.stderr)
        print(
            "\nA file that parsed before and does not now is a parser regression: "
            "same bytes (fetch.sh verified the sha256), different MuPDF or different "
            "code. Fix or re-baseline deliberately.",
            file=sys.stderr,
        )
        return 1

    print(f"\nno regressions: {len(measured)} files still parse, {len(before)} in the baseline")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
