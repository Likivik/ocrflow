"""A damaged PDF must be skipped, not sent to an engine that cannot read it.

Three production files reached Google and came back
``400 Unsupported input file format`` — then were retried. They are not exotic:
one 625 KB contract that pymupdf refuses to open at all, and two receipts (7 KB
and 14 KB) that open with zero pages. No engine can OCR them, and a retry can
only reproduce the answer.

The classifier already opens every PDF, so it is the place that knows.
"""

from __future__ import annotations

import pymupdf

from nc_ocr_flow import webhook_server
from nc_ocr_flow.pdf_classify import broken_pdf_reason, classify_pdf


def _unopenable_pdf(path):
    """A file with a PDF header and nothing a parser can use."""
    path.write_bytes(b"%PDF-1.7\n" + b"\x00" * 512)
    return path


def _zero_page_pdf(path):
    """Structurally valid enough to open, with no pages in it."""
    path.write_bytes(
        b"%PDF-1.4\n"
        b"1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        b"2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\n"
        b"trailer<</Root 1 0 R>>\n%%EOF\n"
    )
    return path


def _normal_pdf(path):
    doc = pymupdf.open()
    page = doc.new_page()
    page.insert_text((50, 80), "Contract 231953")
    doc.save(str(path))
    doc.close()
    return path


def test_unopenable_pdf_is_reported(tmp_path):
    assert broken_pdf_reason(_unopenable_pdf(tmp_path / "broken.pdf"))


def test_zero_page_pdf_is_reported(tmp_path):
    assert "0 pages" in (broken_pdf_reason(_zero_page_pdf(tmp_path / "empty.pdf")) or "")


def test_a_readable_pdf_is_not_reported(tmp_path):
    assert broken_pdf_reason(_normal_pdf(tmp_path / "ok.pdf")) is None


def test_classifier_marks_broken_files_and_keeps_them_out_of_ocr(tmp_path):
    """The verdict has to reach a skip, not just an internal flag."""
    for path, expected in (
        (_unopenable_pdf(tmp_path / "broken.pdf"), "unopenable"),
        (_zero_page_pdf(tmp_path / "empty.pdf"), "0 pages"),
    ):
        verdict = classify_pdf(path)
        assert verdict["needs_ocr"] is False
        assert expected in verdict["broken_reason"]

        needs_ocr, reason, _origin = webhook_server._needs_ocr_decision(path, force=False)
        assert needs_ocr is False
        assert reason == webhook_server.SKIP_REASON_BROKEN_PDF


def test_a_readable_pdf_is_still_offered_to_the_engine(tmp_path):
    path = _normal_pdf(tmp_path / "ok.pdf")
    verdict = classify_pdf(path)
    assert "broken_reason" not in verdict
