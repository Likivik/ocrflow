"""Document vs photo classifier for uploaded images.

Uses MobileNetV2 ONNX model (CPU, ~100ms) to decide:
  - document → convert to PDF, run OCR pipeline
  - photo → skip (stays in Nextcloud untouched, NOT moved to immich)

API:
    classify(image_path) -> ClassifyResult(is_document, reason)
"""

from __future__ import annotations

import contextlib
import logging
from dataclasses import dataclass
from pathlib import Path

from .config import env_str

log = logging.getLogger(__name__)

DOC = "document"
PHOTO = "photo"

# MobileNetV2 input: 224x224 normalized with ImageNet mean/std
_MOBILENET_SIZE = 224
_MOBILENET_MEAN = [0.485, 0.456, 0.406]
_MOBILENET_STD = [0.229, 0.224, 0.225]

_MOBILENET_SESSION = None


@dataclass(frozen=True)
class ClassifyResult:
    is_document: bool
    reason: str  # "mobilenet" | "error:ClassName"


def _get_session():
    global _MOBILENET_SESSION
    if _MOBILENET_SESSION is None:
        import onnxruntime as ort

        model_path = env_str(
            "NC_OCR_MOBILENET_MODEL",
            "/etc/static/nc-ocr/mobilenet_v3_small_fp32.onnx",
        )
        _MOBILENET_SESSION = ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])
    return _MOBILENET_SESSION


class ImageTooLarge(RuntimeError):
    """The image is bigger than the decoder will open at all.

    Pillow refuses anything above its decompression-bomb ceiling
    (2 x Image.MAX_IMAGE_PIXELS, 178,956,970 px by default) at ``open()``
    time. That is a property of the FILE, not of the model, and it used to
    be reported as ``ClassifierUnavailable`` — which told the operator to
    fix a model that was working.
    """


class ClassifierUnavailable(RuntimeError):
    """The image classifier cannot run (model missing, ONNX broken, …).

    Deliberately NOT a skip reason. Failing open to ``is_document=True``
    means one broken model quietly converts every image in every folder to
    a PDF and deletes the sources — a mass mutation nobody asked for, with
    a Vision bill attached. Failing closed stops the image pipeline and
    surfaces the reason instead; documents keep their original images
    until the model is fixed.
    """


#: Formats whose decoder can serve a reduced-size version without ever building
#: the full bitmap. ``draft()`` is a no-op everywhere else.
_DRAFTABLE = frozenset({"JPEG", "MPO", "TIFF"})

#: The largest image this worker will ever attempt, in pixels: ~30 000 x 13 000.
#: A big scan, never a decompression bomb.
_MAX_PIXELS_WE_WILL_TRY = 400_000_000


def _open_thumbnail_source(Image, path: Path):
    """Open ``path`` and ask the decoder for a thumbnail-sized decode.

    Pillow refuses to OPEN an image above its decompression-bomb ceiling
    (2 x ``Image.MAX_IMAGE_PIXELS``). That check runs while the header is
    parsed, i.e. BEFORE ``draft()`` can be requested — which is why "just draft
    first" does not work, and why a 247 M px scan was reported as a broken
    model.

    The guard is lifted for the header parse only and restored immediately.
    Nothing is decoded at that point, so no memory is committed; the decode that
    follows is a draft, 1/64 of the pixels at 1/8 scale. A format that cannot
    draft is held to Pillow's own ceiling, because there the full bitmap really
    would be allocated.
    """
    previous = Image.MAX_IMAGE_PIXELS
    Image.MAX_IMAGE_PIXELS = None
    try:
        raw = Image.open(path)
    finally:
        Image.MAX_IMAGE_PIXELS = previous

    pixels = raw.size[0] * raw.size[1]
    if pixels > _MAX_PIXELS_WE_WILL_TRY:
        raise ImageTooLarge(
            f"image too large to analyse ({pixels} pixels, limit {_MAX_PIXELS_WE_WILL_TRY})"
        )
    if raw.format in _DRAFTABLE:
        with contextlib.suppress(Exception):
            raw.draft("RGB", (_MOBILENET_SIZE, _MOBILENET_SIZE))
    elif pixels > (previous or _MAX_PIXELS_WE_WILL_TRY):
        raise ImageTooLarge(
            f"image too large to analyse without a draft decode "
            f"({pixels} pixels, {raw.format or 'unknown'} format)"
        )
    return raw


def classify(image_path: str | Path) -> ClassifyResult:
    """Classify image as document or photo.

    Raises :class:`ClassifierUnavailable` when the classifier itself cannot
    run. A *successful* run whose verdict happens to be wrong is a
    different (and much cheaper) problem: photos get skipped, documents get
    OCR'd, and neither destroys data.
    """
    path = Path(image_path)
    try:
        import numpy as np
        from PIL import Image

        # Draft before load: for JPEG the decoder then produces a 1/2, 1/4 or
        # 1/8 image directly, without ever materialising the full bitmap. The
        # classifier wants 224x224, so the full-size decode was waste — and for
        # a 247 M px scan it was impossible: Pillow's decompression-bomb guard
        # rejects the file at open(), the resize never runs, and a healthy photo
        # was reported as a broken model.
        raw = _open_thumbnail_source(Image, path)
        img = raw.convert("RGB").resize(
            (_MOBILENET_SIZE, _MOBILENET_SIZE), Image.Resampling.BILINEAR
        )
        arr = np.asarray(img, dtype=np.float32) / 255.0
        arr = (arr - _MOBILENET_MEAN) / _MOBILENET_STD
        arr = arr.transpose(2, 0, 1)[None]  # HWC → NCHW

        sess = _get_session()
        out = sess.run(None, {sess.get_inputs()[0].name: arr.astype(np.float32)})[0]
        is_doc = int(out[0].argmax()) == 0
        return ClassifyResult(is_document=is_doc, reason="mobilenet")

    except ImageTooLarge:
        # Already the right answer: the file is the problem, not the model.
        raise
    except Exception as exc:
        from PIL import Image

        if isinstance(exc, Image.DecompressionBombError):
            raise ImageTooLarge(
                f"image too large to analyse ({type(exc).__name__}: {exc})"
            ) from exc
        raise ClassifierUnavailable(
            f"image classifier unavailable ({type(exc).__name__}: {exc}); "
            "refusing to guess document-vs-photo. Fix the model "
            "(_MOBILENET_MODEL / NC_OCR_MOBILENET_MODEL) and re-run."
        ) from exc


__all__ = ["DOC", "PHOTO", "ClassifierUnavailable", "ClassifyResult", "classify"]
