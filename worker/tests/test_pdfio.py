"""The one PDF writer: incremental first, verified atomic rewrite second.

These tests pin the rules the two former copies disagreed on: fsync before the
rename, mode inheritance, image verification (not just pages), and no temp file
left on any path. The stamp-path tests in ``test_stamp_save_fallback.py`` still
run through ``_save_stamped_pdf``, which now delegates here — that is the point:
one implementation, two call sites.
"""

from __future__ import annotations

import os
import stat

import pymupdf
import pytest

from nc_ocr_flow import pdfio
from nc_ocr_flow.pdfio import write_in_place


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
    """Proxy for a document whose incremental save is refused."""

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


def test_incremental_path_reports_without_temp(tmp_path):
    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    doc = pymupdf.open(str(pdf))
    try:
        report = write_in_place(doc, pdf)
    finally:
        doc.close()

    assert report.mode == "incremental"
    assert (report.pages, report.images) == (2, 2)
    assert not list(tmp_path.glob("*.write.tmp")), "temp file left behind"


def test_refused_incremental_save_rewrites_verifies_and_inherits_mode(tmp_path):
    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    os.chmod(pdf, 0o600)

    doc = pymupdf.open(str(pdf))
    try:
        report = write_in_place(_RefusesIncrementalSave(doc), pdf, garbage=4)
    finally:
        doc.close()

    assert report.mode == "rewrite"
    assert not list(tmp_path.glob("*.write.tmp")), "temp file left behind"
    # Without mode inheritance a 0600 user scan silently becomes 0644.
    assert stat.S_IMODE(pdf.stat().st_mode) == 0o600, "mode was not inherited"

    check = pymupdf.open(str(pdf))
    try:
        assert check.page_count == 2
        assert sum(len(p.get_images()) for p in check) == 2
    finally:
        check.close()


def test_damaging_rewrite_never_replaces_the_original(tmp_path):
    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    original = pdf.read_bytes()

    doc = pymupdf.open(str(pdf))
    try:
        with pytest.raises(RuntimeError, match="refusing to replace the original"):
            write_in_place(_RefusesIncrementalSave(doc, damage_on_save=True), pdf)
    finally:
        doc.close()

    assert pdf.read_bytes() == original, "the original was replaced anyway"
    assert not list(tmp_path.glob("*.write.tmp"))


def test_bytes_are_fsynced_before_the_rename(tmp_path, monkeypatch):
    """Durability before visibility: fsync(file) → replace → fsync(dir)."""
    events: list[str] = []
    real_replace = os.replace

    monkeypatch.setattr(pdfio, "_fsync_file", lambda path: events.append("fsync_file"))
    monkeypatch.setattr(pdfio, "_fsync_dir", lambda path: events.append("fsync_dir"))
    monkeypatch.setattr(
        pdfio.os,
        "replace",
        lambda source, target: (events.append("replace"), real_replace(source, target))[1],
    )

    pdf = _two_page_pdf_with_images(tmp_path / "scan.pdf")
    doc = pymupdf.open(str(pdf))
    try:
        write_in_place(_RefusesIncrementalSave(doc), pdf)
    finally:
        doc.close()

    assert events == ["fsync_file", "replace", "fsync_dir"], events
