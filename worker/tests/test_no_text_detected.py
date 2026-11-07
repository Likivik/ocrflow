"""An engine that finds no text is a skip, not a failure.

Three production files (a blank signature sheet, a stamp, a sample form) came
back "produced 0 blocks" and were marked failed. Both engines genuinely find no
text in them — Google Vision returns an annotation-free page and tesseract
returns 0 characters — so the job was retried forever over a correct answer.

The guard must still shout when the engine *did* return text and the block
parser dropped it: that is the documented bbox-parser bug, and silencing it
would stamp files with an empty text layer.
"""

from __future__ import annotations

from types import SimpleNamespace

import pytest

from nc_ocr_flow import webhook_server
from nc_ocr_flow.ocr_models import OcrResult


def _result(*pages, engine="google"):
    return SimpleNamespace(engine_used=engine, page_results=list(pages))


def _page(blocks=(), had_text=True):
    return OcrResult(
        blocks=list(blocks),
        page_width=100.0,
        page_height=200.0,
        source_had_text=had_text,
    )


def test_annotation_free_page_is_skipped_not_failed():
    """Vision answered and carried no annotation: nothing to read, so skip."""
    result = _result(_page(had_text=False))

    reason = webhook_server._assert_ocr_produced_blocks(result, "/files/stamp.jpg.pdf")

    assert reason == "no_text_detected"


def test_multi_page_file_needs_every_page_to_be_empty():
    """One page with text means this is not a text-free file."""
    result = _result(_page(had_text=False), _page(blocks=[object()]))

    assert webhook_server._assert_ocr_produced_blocks(result, "/files/mixed.pdf") is None


def test_text_present_but_no_blocks_still_raises():
    """The parser bug stays loud: the engine had text, we produced nothing."""
    result = _result(_page(had_text=True))

    with pytest.raises(RuntimeError, match="produced 0 blocks"):
        webhook_server._assert_ocr_produced_blocks(result, "/files/contract.pdf")


def test_blocks_present_is_clean():
    result = _result(_page(blocks=[object()]))

    assert webhook_server._assert_ocr_produced_blocks(result, "/files/ok.pdf") is None


def test_engines_without_the_evidence_keep_the_old_behaviour():
    """minimax does not populate source_had_text, so it must still raise."""
    page = OcrResult(blocks=[], page_width=0.0, page_height=0.0)
    assert page.source_had_text is True

    with pytest.raises(RuntimeError, match="produced 0 blocks"):
        webhook_server._assert_ocr_produced_blocks(_result(page, engine="minimax"), "/f.pdf")
