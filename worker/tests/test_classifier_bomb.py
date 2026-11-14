"""The classifier must not blame the model for a file the decoder refused.

A 247 M px scan on production failed with::

    image classifier unavailable (DecompressionBombError: Image size
    (247698504 pixels) exceeds limit of 178956970 pixels ...); refusing to
    guess document-vs-photo. Fix the model (_MOBILENET_MODEL ...) and re-run.

Nothing was wrong with the model. Pillow refuses to *open* an image above its
decompression-bomb ceiling, and the check runs while the header is parsed — so
``draft()`` cannot be requested first. ``_open_thumbnail_source`` lifts the
guard for the header read only, restores it, and then asks for a draft decode.

Three rules, all pinned here:

1. a big JPEG is opened (guard lifted for the header) and drafted, never fully
   decoded;
2. a big file in a format that cannot draft is refused, not decoded;
3. an oversized file is ``ImageTooLarge``, never a broken model.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from PIL import Image

from nc_ocr_flow import classifier


class _RecordingImage:
    """Stands in for a PIL image while recording the draft request."""

    def __init__(self, calls, size, fmt="JPEG"):
        self._calls = calls
        self.size = size
        self.format = fmt

    def draft(self, mode, size):
        self._calls.append((mode, size))
        return None

    def convert(self, mode):
        return Image.new("RGB", (8, 8), "white")

    def resize(self, size, resample=None):
        return Image.new("RGB", size, "white")


def test_a_big_jpeg_is_drafted_rather_than_decoded(monkeypatch, tmp_path: Path) -> None:
    calls: list[tuple[str, tuple[int, int]]] = []
    seen_max_pixels: list[int | None] = []

    def _open(_path):
        seen_max_pixels.append(Image.MAX_IMAGE_PIXELS)
        return _RecordingImage(calls, (20_000, 10_000))

    monkeypatch.setattr(Image, "open", _open)
    before = Image.MAX_IMAGE_PIXELS

    with pytest.raises(classifier.ClassifierUnavailable):
        # The ONNX model is absent here; that path raises AFTER the decode,
        # which is the part under test.
        classifier.classify(tmp_path / "scan.jpg")

    assert calls == [("RGB", (224, 224))], "the draft must be requested before any decode"
    assert seen_max_pixels == [None], "the bomb guard is lifted for the header read only"
    assert before == Image.MAX_IMAGE_PIXELS, "the guard must be restored, whatever happens"


def test_a_big_png_is_refused_instead_of_decoded(monkeypatch, tmp_path: Path) -> None:
    def _open(_path):
        return _RecordingImage([], (20_000, 10_000), fmt="PNG")

    monkeypatch.setattr(Image, "open", _open)

    with pytest.raises(classifier.ImageTooLarge):
        classifier.classify(tmp_path / "scan.png")


def test_an_impossible_image_is_not_a_broken_model(monkeypatch, tmp_path: Path) -> None:
    def _open(_path):
        return _RecordingImage([], (40_000, 40_000))  # 1.6 G px

    monkeypatch.setattr(Image, "open", _open)

    with pytest.raises(classifier.ImageTooLarge) as caught:
        classifier.classify(tmp_path / "scan.jpg")

    assert "too large" in str(caught.value)
    assert "Fix the model" not in str(caught.value)
