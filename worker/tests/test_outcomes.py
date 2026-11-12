"""The outcomes vocabulary on the worker side: the stamp token, the engine bar
comparison, and who wrote a PDF's text layer. Red-first for PR 1 of the
outcomes plan (2026-11-12); every name here was chosen by the owner in review.

The classification fixtures are built, not stored: six small PDFs generated
with PyMuPDF at test time, each carrying exactly the provenance signal the
rule it exercises needs. A stored binary fixture rots; a generator documents
itself.
"""

from __future__ import annotations

from pathlib import Path

import pymupdf
import pytest

from nc_ocr_flow.provenance import (
    LEGACY_STAMP_KEYWORD,
    build_stamp_token,
    classify_text_layer,
    engine_version_at_or_above,
    ocr_layer_origin,
    parse_stamp_token,
)

# -- stamp token --------------------------------------------------------------


def test_new_token_round_trips() -> None:
    token = build_stamp_token("0.5", "google", "3.15.0")
    assert token == "ocrflow/0.5/google/3.15.0"
    parsed = parse_stamp_token(f"nc-ocr-flow, ocr, {token}")
    assert parsed is not None
    assert parsed.engine == "google"
    assert parsed.engine_version == "3.15.0"
    assert parsed.stamp_version == "0.5"
    assert not parsed.legacy


def test_legacy_keyword_reads_as_ours_with_unknown_version() -> None:
    # 1,489 files carry the old keyword. One release of read-compat: it means
    # "ours", version unknown — which is below any bar by definition.
    parsed = parse_stamp_token("nc-ocr-flow, ocr, stamp_version=0.4")
    assert parsed is not None
    assert parsed.legacy
    assert parsed.engine == ""
    assert parsed.engine_version == ""
    assert parsed.stamp_version == ""


@pytest.mark.parametrize(
    "keywords",
    [
        None,
        "",
        "ocr, unrelated",
        "ocrflow/only-three",  # wrong arity
        "ocrflow/0.5//3.15.0",  # empty engine
        "ocrflow/0.5/google/",  # empty engine version
        "ocrflow//google/3.15.0",  # empty stamp version
    ],
)
def test_malformed_or_absent_tokens_parse_to_none(keywords: str | None) -> None:
    assert parse_stamp_token(keywords) is None


def test_a_bare_legacy_term_among_others_is_still_found() -> None:
    assert parse_stamp_token(["ocr", LEGACY_STAMP_KEYWORD]) is not None


# -- the bar -----------------------------------------------------------------


@pytest.mark.parametrize(
    ("version", "bar", "expected"),
    [
        ("3.15.0", "3.15.0", True),
        ("3.16.1", "3.15.0", True),
        ("3.9.0", "3.15.0", False),  # the string compare would say otherwise
        ("3.14.9", "3.15.0", False),
        ("3.15", "3.15.0", True),  # a shorter version pads with zeros
        ("", "3.15.0", False),  # missing version = below the bar
    ],
)
def test_version_comparison_is_numeric(version: str, bar: str, expected: bool) -> None:
    assert engine_version_at_or_above(version, bar) is expected


# -- fixtures ----------------------------------------------------------------


def _pdf(
    tmp_path: Path,
    name: str,
    *,
    keywords: str = "",
    producer: str = "",
    creator: str = "",
    text: str | None = None,
    image: bool = False,
    invisible_text: bool = False,
) -> Path:
    path = tmp_path / name
    doc = pymupdf.open()
    page = doc.new_page()
    if image:
        # A raster page: one full-page image, no text.
        pix = pymupdf.Pixmap(pymupdf.csGRAY, pymupdf.IRect(0, 0, 120, 160))
        pix.clear_with(128)
        page.insert_image(page.rect, pixmap=pix)
    if text is not None:
        page.insert_text((20, 40), text)
    if invisible_text:
        # Text drawn under the page image: render mode 3 (invisible).
        tw = pymupdf.TextWriter(page.rect)
        tw.append((20, 40), "invisible layer")
        tw.write_text(page, render_mode=3)
    doc.set_metadata(
        {
            "producer": producer,
            "creator": creator,
            **({"keywords": keywords} if keywords else {}),
        }
    )
    doc.save(path)
    doc.close()
    return path


@pytest.fixture()
def ours_tesseract(tmp_path: Path) -> Path:
    return _pdf(
        tmp_path, "ours_tesseract.pdf", keywords="nc-ocr-flow, ocr, ocrflow/0.5/tesseract/5.3.0"
    )


@pytest.fixture()
def ours_google(tmp_path: Path) -> Path:
    return _pdf(tmp_path, "ours_google.pdf", keywords="nc-ocr-flow, ocr, ocrflow/0.5/google/3.15.0")


@pytest.fixture()
def word_export(tmp_path: Path) -> Path:
    return _pdf(
        tmp_path,
        "word.pdf",
        producer="Microsoft Word",
        text="A typed document body with enough prose to read as a text layer.",
    )


@pytest.fixture()
def abbyy_scan(tmp_path: Path) -> Path:
    # An ABBYY-style scan: image page, invisible OCR text under it, and the
    # tool named in the metadata.
    return _pdf(tmp_path, "abbyy.pdf", creator="ABBYY FineReader", image=True, invisible_text=True)


@pytest.fixture()
def bare_scan(tmp_path: Path) -> Path:
    # A raster document nobody has read yet: image, no text, no trace.
    return _pdf(tmp_path, "bare_scan.pdf", producer="Canon iR-ADV", image=True)


@pytest.fixture()
def blank_scan(tmp_path: Path) -> Path:
    # A blank page: no image content to speak of, no text, no trace. It is NOT
    # a skip — the classifier cannot know a blank from an unread scan.
    return _pdf(tmp_path, "blank.pdf")


# -- ocr_layer_origin ---------------------------------------------------------


def test_our_tesseract_pdf_is_ours(ours_tesseract: Path) -> None:
    origin = ocr_layer_origin(ours_tesseract)
    assert origin.origin == "ours"
    assert origin.engine == "tesseract"
    assert origin.engine_version == "5.3.0"


def test_our_google_pdf_is_ours(ours_google: Path) -> None:
    origin = ocr_layer_origin(ours_google)
    assert origin.origin == "ours"
    assert origin.engine == "google"
    assert origin.engine_version == "3.15.0"


def test_a_word_export_is_native(word_export: Path) -> None:
    origin = ocr_layer_origin(word_export)
    assert origin.origin == "native"
    assert origin.token.startswith("native/")
    assert "Word" in origin.token


def test_an_abbyy_scan_is_suspected_foreign(abbyy_scan: Path) -> None:
    assert ocr_layer_origin(abbyy_scan).origin == "suspected_foreign"


def test_a_bare_scan_has_no_layer_and_needs_ocr(bare_scan: Path) -> None:
    # The defect this replaces: "has a text layer → born digital" skipped
    # bare scans whose only content was an image. A bare raster document is
    # work, not a verdict.
    assert ocr_layer_origin(bare_scan).origin == "none"


def test_a_blank_scan_needs_ocr_not_a_skip(blank_scan: Path) -> None:
    assert ocr_layer_origin(blank_scan).origin == "none"


def test_classification_is_read_only(bare_scan: Path) -> None:
    before = bare_scan.read_bytes()
    ocr_layer_origin(bare_scan)
    assert bare_scan.read_bytes() == before, "classification must never touch the file"


# -- classify_text_layer: the six outcomes -----------------------------------


def test_ours_above_the_bar_is_nothing_to_do(ours_google: Path) -> None:
    verdict = classify_text_layer(ours_google, {"google": "3.15.0"})
    assert verdict["code"] == "skip_ocred_by_ocrflow"
    assert verdict["origin"] == "ours"
    assert verdict["engine"] == "google"
    assert verdict["engine_version"] == "3.15.0"
    assert verdict["below_bar"] is False


def test_ours_below_the_bar_is_a_redo_candidate(ours_tesseract: Path) -> None:
    verdict = classify_text_layer(ours_tesseract, {"google": "3.15.0"})
    assert verdict["code"] == "skip_ocred_by_ocrflow"
    assert verdict["below_bar"] is True, "an unmeasured engine is never good enough"


def test_ours_missing_version_is_a_redo_candidate(tmp_path: Path) -> None:
    legacy = _pdf(tmp_path, "legacy.pdf", keywords="nc-ocr-flow, ocr")
    verdict = classify_text_layer(legacy, {"google": "3.15.0"})
    assert verdict["code"] == "skip_ocred_by_ocrflow"
    assert verdict["below_bar"] is True, "unknown version cannot be trusted as done"


def test_word_export_is_nothing_to_do(word_export: Path) -> None:
    assert classify_text_layer(word_export, {"google": "3.15.0"})["code"] == "skip_text_native"


def test_abbyy_is_suspected_foreign(abbyy_scan: Path) -> None:
    assert (
        classify_text_layer(abbyy_scan, {"google": "3.15.0"})["code"]
        == "skip_ocred_by_suspected_foreign"
    )


@pytest.mark.parametrize("fixture_name", ["bare_scan", "blank_scan"])
def test_scans_are_needs_ocr(fixture_name: str, request: pytest.FixtureRequest) -> None:
    path = request.getfixturevalue(fixture_name)
    verdict = classify_text_layer(path, {"google": "3.15.0"})
    assert verdict["code"] == "needs_ocr"
    assert verdict["origin"] == "none"
