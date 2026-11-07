"""The two real sites behind the failures my first fixes missed.

Both were caught by deploying and watching the next folder run, not by the tests
I wrote first — which is the reason the deployed check matters.

1. Vision returns a ``full_text_annotation`` **with page dimensions and no
   words**. Measured on a stamp image: ``blocks=0, had_text=True, dims=496x565``.
   Checking ``full is None`` therefore never fired, and text-free pages kept
   failing with "produced 0 blocks".

2. The finalize fallback in ``ocr.py`` called ``doc.save(out_pdf)`` — a
   non-incremental save onto the file PyMuPDF had opened, which raises
   ``ValueError("save to original must be incremental")``. It replaced one
   refusal with another, after the engine had already been paid.
"""

from __future__ import annotations

from types import SimpleNamespace

import pymupdf
import pytest

from nc_ocr_flow import ocr
from nc_ocr_flow.engines.google_engine import _annotation_has_text


def test_missing_annotation_has_no_text():
    assert _annotation_has_text(None) is False


def test_annotation_with_dimensions_but_no_words_has_no_text():
    """The production shape: an annotation shell with geometry and no words."""
    stamp = SimpleNamespace(text="", pages=[SimpleNamespace(width=496, height=565)])
    assert _annotation_has_text(stamp) is False


def test_whitespace_only_annotation_has_no_text():
    assert _annotation_has_text(SimpleNamespace(text="  \n\t ")) is False


def test_annotation_with_words_has_text():
    assert _annotation_has_text(SimpleNamespace(text="ДОГОВОР 231953")) is True


class _RefusesIncrementalSave:
    def __init__(self, doc, *, damage_on_save: bool = False):
        self._doc = doc
        self._damage_on_save = damage_on_save

    def __getattr__(self, name):
        return getattr(self._doc, name)

    def __iter__(self):
        return iter(self._doc)

    def saveIncr(self, *args, **kwargs):
        raise ValueError("save to original must be incremental")

    def save(self, filename, *args, **kwargs):
        if self._damage_on_save:
            other = pymupdf.open()
            other.new_page()
            other.save(filename)
            other.close()
            return None
        return self._doc.save(filename, *args, **kwargs)


def _two_page_pdf(path):
    doc = pymupdf.open()
    for _ in range(2):
        doc.new_page(width=200, height=300)
    doc.save(str(path))
    doc.close()
    return path


def test_finalize_rewrites_when_the_append_is_refused(tmp_path):
    pdf = _two_page_pdf(tmp_path / "scan.pdf")
    doc = pymupdf.open(str(pdf))
    try:
        ocr._save_finalized(_RefusesIncrementalSave(doc), pdf)
    finally:
        doc.close()

    assert not list(tmp_path.glob("*.finalize.tmp"))
    check = pymupdf.open(str(pdf))
    try:
        assert check.page_count == 2
    finally:
        check.close()


def test_finalize_never_replaces_the_file_with_a_short_rewrite(tmp_path):
    pdf = _two_page_pdf(tmp_path / "scan.pdf")
    original = pdf.read_bytes()
    doc = pymupdf.open(str(pdf))
    try:
        with pytest.raises(RuntimeError, match="refusing to replace the original"):
            ocr._save_finalized(_RefusesIncrementalSave(doc, damage_on_save=True), pdf)
    finally:
        doc.close()

    assert pdf.read_bytes() == original
    assert not list(tmp_path.glob("*.finalize.tmp"))
