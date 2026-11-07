"""A JPEG with an out-of-spec EXIF orientation must not kill the job.

EXIF Orientation defines 1-8. A tag of 0 is broken metadata, not "no
rotation", and img2pdf refuses the whole file over it:

    ExifOrientationError: Invalid rotation (0)

Two production files failed exactly that way. Page orientation is decided from
the pixels afterwards (``preorient_pdf``, PP-LCNet), so an unreadable tag must
be ignored rather than fatal — and a *valid* tag must still be honoured.
"""

from __future__ import annotations

import pymupdf
from PIL import Image

from nc_ocr_flow.webhook_server import _image_to_pdf


def _jpeg_with_orientation(path, orientation: int, size=(120, 60)):
    """Write a JPEG carrying ``orientation`` in its EXIF tag.

    The stripe keeps the page from being blank, so the conversion is doing real
    work rather than round-tripping an empty canvas.
    """
    image = Image.new("RGB", size, "white")
    for x in range(size[0] // 4, size[0] // 2):
        for y in range(size[1]):
            image.putpixel((x, y), (0, 0, 0))
    exif = image.getexif()
    exif[274] = orientation  # 274 = Orientation
    image.save(path, format="JPEG", exif=exif.tobytes())
    return path


def test_out_of_spec_orientation_zero_is_ignored(tmp_path):
    """Orientation 0 is not in the spec; the file must still convert."""
    source = _jpeg_with_orientation(tmp_path / "weird.jpg", 0)

    result = _image_to_pdf(source)

    document = pymupdf.open(result)
    try:
        assert document.page_count == 1
    finally:
        document.close()


def test_valid_orientation_six_still_rotates(tmp_path):
    """Orientation 6 is a real 90° turn and must not be discarded with the junk."""
    source = _jpeg_with_orientation(tmp_path / "rot90.jpg", 6, size=(120, 60))

    result = _image_to_pdf(source)

    document = pymupdf.open(result)
    try:
        page = document[0]
        # A 120x60 landscape image rotated 90° becomes a portrait page.
        assert page.rect.width < page.rect.height, (
            f"expected a portrait page after rotation, got {page.rect}"
        )
    finally:
        document.close()
