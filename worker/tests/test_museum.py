"""The museum: every known failure shape, pinned as an outcome, not a message.

Each row is a shape that reached production once. The assertion is the
*decision* (skip vs bug vs proceed), because that is what a retry policy keys
on; the wording of an error is allowed to change, the outcome is not.
"""

from __future__ import annotations

import pymupdf
import pytest

from nc_ocr_flow.ocr import ProcessResult
from nc_ocr_flow.ocr_models import OcrResult
from nc_ocr_flow.pdf_classify import broken_pdf_reason
from nc_ocr_flow.webhook_server import _assert_ocr_produced_blocks


def _one_page_pdf(path):
    doc = pymupdf.open()
    page = doc.new_page(width=120, height=160)
    page.insert_text((10, 40), "hello")
    doc.save(str(path))
    doc.close()
    return path


class TestNoText:
    """A page with nothing to read is a skip, never a failure and never a retry."""

    def test_engine_answers_with_no_annotation_is_a_skip(self):
        result = ProcessResult(
            output_pdf="/f.pdf",
            engine_used="google",
            page_results=[
                OcrResult(blocks=[], source_had_text=False, page_width=496, page_height=565)
            ],
        )
        assert _assert_ocr_produced_blocks(result, "/f.pdf") == "no_text_detected"

    def test_engine_sent_text_we_could_not_parse_is_a_bug(self):
        result = ProcessResult(
            output_pdf="/f.pdf",
            engine_used="google",
            page_results=[
                OcrResult(blocks=[], source_had_text=True, page_width=496, page_height=565)
            ],
        )
        with pytest.raises(RuntimeError):
            _assert_ocr_produced_blocks(result, "/f.pdf")

    def test_healthy_page_proceeds(self):
        result = ProcessResult(
            output_pdf="/f.pdf",
            engine_used="google",
            page_results=[
                OcrResult(blocks=[object()], source_had_text=True, page_width=496, page_height=565)
            ],
        )
        assert _assert_ocr_produced_blocks(result, "/f.pdf") is None


class TestBrokenPdf:
    """A damaged file is refused before any paid engine call."""

    def test_unopenable_bytes_have_a_reason(self, tmp_path):
        bad = tmp_path / "damaged.pdf"
        bad.write_bytes(b"%PDF-1.4\n not a real document " + b"\x00" * 200)
        assert broken_pdf_reason(bad) is not None

    def test_zero_byte_file_has_a_reason(self, tmp_path):
        empty = tmp_path / "empty.pdf"
        empty.write_bytes(b"")
        assert broken_pdf_reason(empty) is not None

    def test_a_real_pdf_has_no_reason(self, tmp_path):
        good = _one_page_pdf(tmp_path / "good.pdf")
        assert broken_pdf_reason(good) is None
