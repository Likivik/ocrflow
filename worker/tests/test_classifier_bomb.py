"""The classifier must not blame the model for a file the decoder refused.

A 247 M px scan on production failed with::

    image classifier unavailable (DecompressionBombError: Image size
    (247698504 pixels) exceeds limit of 178956970 pixels ...); refusing to
    guess document-vs-photo. Fix the model (_MOBILENET_MODEL ...) and re-run.

Nothing was wrong with the model. Pillow refuses to *open* an image above its
decompression-bomb ceiling, and ``classify()`` called ``Image.open()`` before
any resize — so the 224x224 shrink the classifier actually needs never ran.

Two rules, both pinned here:

1. ask the decoder for a draft (a 1/2, 1/4 or 1/8 JPEG) before decoding;
2. report an oversized file as ``ImageTooLarge``, never as a broken model.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from PIL import Image

from nc_ocr_flow import classifier


class _RecordingImage:
    """Stands in for a PIL image while recording the draft request."""

    def __init__(self, calls: list[tuple[str, tuple[int, int]]]) -> None:
        self._calls = calls

    def draft(self, mode: str, size: tuple[int, int]):
        self._calls.append((mode, size))
        return None

    def convert(self, mode: str):
        return Image.new("RGB", (8, 8), "white")

    def resize(self, size, resample=None):
        return Image.new("RGB", size, "white")


def test_draft_is_requested_before_the_decode(monkeypatch, tmp_path: Path) -> None:
    calls: list[tuple[str, tuple[int, int]]] = []
    monkeypatch.setattr(Image, "open", lambda _path: _RecordingImage(calls))

    # The model is absent in this environment; that paths raises
    # ClassifierUnavailable AFTER the decode, which is the part under test.
    with pytest.raises(classifier.ClassifierUnavailable):
        classifier.classify(tmp_path / "scan.jpg")

    assert calls == [("RGB", (224, 224))], (
        "the classifier must ask the decoder for a draft thumbnail first — "
        "without it, Pillow opens the full bitmap and the bomb guard rejects it"
    )


def test_a_decompression_bomb_is_not_a_broken_model(monkeypatch, tmp_path: Path) -> None:
    def _boom(_path):
        raise Image.DecompressionBombError(
            "Image size (247698504 pixels) exceeds limit of 178956970 pixels"
        )

    monkeypatch.setattr(Image, "open", _boom)

    with pytest.raises(classifier.ImageTooLarge) as caught:
        classifier.classify(tmp_path / "scan.jpg")

    message = str(caught.value)
    assert "too large" in message
    assert "Fix the model" not in message, (
        "a file the decoder refused must not read as a deployment problem"
    )
