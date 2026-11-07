"""The stamp must survive a file PyMuPDF refuses to append to.

``_stamp_metadata`` ended with a bare ``doc.saveIncr()``. That call raises
``ValueError("save to original must be incremental")`` for documents PyMuPDF had
to repair on open, so three production files failed **after** the engine had
already been paid — the OCR was done and thrown away at the last step.

The fallback has to be a rewrite plus an atomic replace, and it has to prove the
rewrite kept the document: replacing a user's scan with a damaged copy is worse
than failing loudly.
"""

from __future__ import annotations

import pymupdf
import pytest

from nc_ocr_flow.webhook_server import _save_stamped_pdf


def _two_page_pdf_with_images(path, pages: int = 2):
    """A PDF whose pages carry images, so an image-count regression is visible."""
    doc = pymupdf.open()
    for index in range(pages):
        page = doc.new_page(width=200, height=300)
        pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 40, 40), 0)
        pix.clear_with(200 + index)
        page.insert_image(pymupdf.Rect(10, 10, 190, 290), pixmap=pix)
    doc.save(str(path))
    doc.close()
    return path


class _RefusesIncrementalSave:
    """Proxy for a PyMuPDF document whose incremental save is refused."""

    def __init__(self, doc, *, damage_on_save: bool = False):
        self._doc = doc
        self._damage_on_save = damage_on_save

    def __getattr__(self, name):
        return getattr(self._doc, name)

    def __iter__(self):
        return iter(self._doc)

    def __len__(self):
        return len(self._doc)

    def saveIncr(self, *args, **kwargs):
        raise ValueError("save to original must be incremental")

    def save(self, filename, *args, **kwargs):
        if self._damage_on_save:
            # Write a single-page document: what a truncated rewrite looks like.
            other = pymupdf.open()
            other.new_page()
            other.save(filename)
            other.close()
            return None
        return self._doc.save(filename, *args, **kwargs)


def test_refused_incremental_save_is_replaced_atomically(tmp_path):
    """The stamp lands, the original is replaced, and no temp file survives."""
    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    doc = pymupdf.open(str(pdf))
    try:
        doc.set_metadata({"keywords": "nc-ocr-flow, ocr"})
        _save_stamped_pdf(_RefusesIncrementalSave(doc), pdf)
    finally:
        doc.close()

    assert not list(tmp_path.glob("*.stamp.tmp")), "temp file left behind"

    check = pymupdf.open(str(pdf))
    try:
        assert check.page_count == 2
        assert sum(len(p.get_images()) for p in check) == 2
        assert "nc-ocr-flow" in (check.metadata or {}).get("keywords", "")
    finally:
        check.close()


def test_a_damaging_rewrite_never_replaces_the_original(tmp_path):
    """A rewrite that loses pages must raise and leave the file untouched."""
    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    original = pdf.read_bytes()
    doc = pymupdf.open(str(pdf))
    try:
        proxy = _RefusesIncrementalSave(doc, damage_on_save=True)
        with pytest.raises(RuntimeError, match="refusing to replace the original"):
            _save_stamped_pdf(proxy, pdf)
    finally:
        doc.close()

    assert pdf.read_bytes() == original, "the original was replaced anyway"
    assert not list(tmp_path.glob("*.stamp.tmp"))


def test_stamp_metadata_survives_a_refused_incremental_save(tmp_path, monkeypatch):
    """The real entry point: ``_stamp_metadata`` must not leak the ValueError.

    This is the production shape — the stamp step runs after the paid OCR, and on
    main the exception escaped here and marked the job failed.
    """
    from nc_ocr_flow import webhook_server

    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    real_open = pymupdf.open

    def fake_open(target, *args, **kwargs):
        doc = real_open(target, *args, **kwargs)
        return _RefusesIncrementalSave(doc)

    monkeypatch.setattr("pymupdf.open", fake_open)

    webhook_server._stamp_metadata(pdf, "google", [0], [])

    check = real_open(str(pdf))
    try:
        assert check.page_count == 2
        assert "nc-ocr-flow" in (check.metadata or {}).get("keywords", "")
    finally:
        check.close()


def test_incremental_save_is_still_the_default(tmp_path):
    """When saveIncr works, no rewrite happens and no temp file appears."""
    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    before = pdf.stat().st_size

    doc = pymupdf.open(str(pdf))
    try:
        doc.set_metadata({"keywords": "nc-ocr-flow, ocr"})
        _save_stamped_pdf(doc, pdf)
    finally:
        doc.close()

    assert pdf.stat().st_size >= before
    assert not list(tmp_path.glob("*.stamp.tmp"))
    check = pymupdf.open(str(pdf))
    try:
        assert "nc-ocr-flow" in (check.metadata or {}).get("keywords", "")
    finally:
        check.close()
