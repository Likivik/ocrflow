#!/usr/bin/env python3
"""Synthetic damaged PDFs for the ocrflow failure paths.

Run: ``python3 tests/fixtures/make_error_fixtures.py [out-dir]``
Default out-dir is ``tests/fixtures/out`` (gitignored). No network, no third
party package: the PDF is assembled byte by byte, so a reviewer can see exactly
what is wrong with each file and no real document is ever committed.

Each fixture, what it proves, and the code it pins:

``zero-bytes.pdf``
    0 bytes, uploaded through WebDAV like any other file. It must never reach
    the worker: the app's own intake decides a zero-byte source is a data
    problem, not work (``lib/Db/Queue.php::enqueue`` → ``recordEmptySource``).
    Pins: ``error_source_file_zero_bytes`` — decided by the app alone, no
    worker in the loop.

``truncated.pdf``
    A valid one-page PDF cut in the middle of its page object: the header, the
    catalog and the page tree survive, the page itself does not. This is the
    production shape the classifier exists for — a contract pymupdf refuses to
    open, sent to Google, answered ``400 Unsupported input file format`` and
    retried. Cut on a computed offset (halfway through object 3), not a magic
    byte count, so editing the objects cannot silently turn it into a file
    that still parses.
    Pins: the worker's ``broken_pdf`` skip (``SKIP_REASON_BROKEN_PDF``), which
    the app canonicalises to ``error_file_broken``.

``bad-xref.pdf``
    The same valid PDF with every xref offset shifted by 9 bytes: the table
    still parses, it just points into the wrong byte — what a half-written or
    badly-rewritten file looks like. Kept as the *negative* half of the pair:
    MuPDF repairs the table and reads the text layer, so this file must NOT be
    reported as broken. It pins that "corrupt xref" alone is not a
    ``error_file_broken`` verdict.
"""

from __future__ import annotations

import sys
from pathlib import Path

#: Objects of a minimal, valid one-page PDF with a real text layer. The
#: content stream's /Length is patched in ``build_pdf`` — a wrong length is a
#: second, unintended defect and the base file must be valid.
OBJECTS = [
    b"<</Type/Catalog/Pages 2 0 R>>",
    b"<</Type/Pages/Kids[3 0 R]/Count 1>>",
    b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]/Contents 4 0 R"
    b"/Resources<</Font<</F1 5 0 R>>>>>>",
    b"<</Length %d>>\nstream\nBT /F1 14 Tf 20 50 Td (fixture: damage me) Tj ET\nendstream",
    b"<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
]

#: The page object (1-based index into OBJECTS): the one ``truncated.pdf`` cuts.
PAGE_OBJECT = 3

#: How far ``bad-xref.pdf`` shifts every xref offset. Any non-zero value makes
#: the table point at the wrong byte; 9 is small enough that the file still
#: reads as text and large enough that a seek lands mid-object.
XREF_SHIFT = 9


def build_pdf() -> tuple[bytes, list[int]]:
    """A minimal valid PDF with a correct xref table.

    Returns the bytes and the byte offset of each object (index 0 is unused,
    objects are 1-based), so the damage below can be described in terms of the
    structure rather than of hardcoded offsets.
    """
    stream = OBJECTS[3].split(b"stream\n", 1)[1].rsplit(b"\nendstream", 1)[0]
    bodies = list(OBJECTS)
    bodies[3] = bodies[3] % len(stream)

    out = bytearray(b"%PDF-1.4\n")
    offsets = [0]
    for number, body in enumerate(bodies, start=1):
        offsets.append(len(out))
        out += b"%d 0 obj\n" % number + body + b"\nendobj\n"

    xref_at = len(out)
    out += b"xref\n0 %d\n" % (len(bodies) + 1)
    out += b"0000000000 65535 f \n"
    for offset in offsets[1:]:
        out += b"%010d 00000 n \n" % offset
    out += b"trailer\n<</Size %d/Root 1 0 R>>\nstartxref\n%d\n%%%%EOF\n" % (
        len(bodies) + 1,
        xref_at,
    )
    return bytes(out), offsets


def truncate(pdf: bytes, offsets: list[int]) -> bytes:
    """Cut the file halfway through the page object."""
    start = offsets[PAGE_OBJECT]
    end = offsets[PAGE_OBJECT + 1] if len(offsets) > PAGE_OBJECT + 1 else len(pdf)
    return pdf[: start + (end - start) // 2]


def corrupt_xref(pdf: bytes) -> bytes:
    """Shift every ``n``-entry offset in the xref table by ``XREF_SHIFT``."""
    head, _, table = pdf.partition(b"xref\n0 ")
    count, _, rest = table.partition(b"\n")
    fixed = []
    for line in rest.split(b"\n"):
        if line.endswith(b" n ") and line[:10].isdigit():
            line = b"%010d%s" % (int(line[:10]) + XREF_SHIFT, line[10:])
        fixed.append(line)
    return head + b"xref\n0 " + count + b"\n" + b"\n".join(fixed)


def main(argv: list[str]) -> int:
    out_dir = Path(argv[1]) if len(argv) > 1 else Path(__file__).with_name("out")
    out_dir.mkdir(parents=True, exist_ok=True)

    pdf, offsets = build_pdf()
    truncated = truncate(pdf, offsets)

    (out_dir / "zero-bytes.pdf").write_bytes(b"")
    (out_dir / "truncated.pdf").write_bytes(truncated)
    (out_dir / "bad-xref.pdf").write_bytes(corrupt_xref(pdf))

    for name in ("zero-bytes.pdf", "truncated.pdf", "bad-xref.pdf"):
        print(f"{name}: {(out_dir / name).stat().st_size} bytes")

    # A fixture that is accidentally fine would make every assertion built on
    # it lie: refuse to produce one.
    if not pdf.startswith(b"%PDF-1.4") or not pdf.endswith(b"%%EOF\n"):
        print("FAIL: the base PDF is no longer a complete file", file=sys.stderr)
        return 1
    if not 0 < len(truncated) < len(pdf):
        print("FAIL: truncated.pdf is not a truncation", file=sys.stderr)
        return 1
    if truncated.endswith(b"endobj\n"):
        print("FAIL: truncated.pdf cuts on an object boundary, not mid-object", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
