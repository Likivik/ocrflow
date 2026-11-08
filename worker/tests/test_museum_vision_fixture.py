"""Replay a REAL Google Vision response to guard the SDK response shape.

PR #55 shipped a silent shape-drift failure: the engine read fields the
SDK no longer exposed, every Word was dropped, and the Google engine
returned zero blocks on every real document. Nothing in the suite caught
it, because every test used hand-written fakes of the response.

This test replays one **real** Vision ``document_text_detection`` payload
(``fixtures/vision/one_page_text.v1.json``) through the real SDK types and
then through the very code paths ``google_engine`` uses to turn a page
into blocks. If the SDK changes the response shape — renamed fields, moved
nesting, a dropped coordinate system — this test goes red instead of the
production job going silently empty.

The fixture is synthetic-derived: it was recorded from a one-page PDF
generated in code, containing only the invented text
``INVOICE 2026-0001 Total 1 234,56 EUR`` / ``Supplier: Synthetic Test
GmbH``. No real document, no private data.

The fixture file itself is always validated as JSON (no SDK needed); the
SDK-dependent assertions skip when ``google-cloud-vision`` is absent.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

FIXTURE = Path(__file__).parent / "fixtures" / "vision" / "one_page_text.v1.json"

EXPECTED_TEXT = "INVOICE 2026-0001 Total 1 234,56 EUR"
EXPECTED_SUPPLIER = "Supplier: Synthetic Test GmbH"


@pytest.fixture(scope="module")
def raw_response() -> dict:
    """The recorded Vision payload, as raw JSON (no SDK required)."""
    assert FIXTURE.exists(), f"missing Vision fixture: {FIXTURE}"
    data = json.loads(FIXTURE.read_text(encoding="utf-8"))
    assert isinstance(data, dict)
    return data


@pytest.fixture(scope="module")
def parsed_response(raw_response):
    """The payload parsed through the REAL SDK type.

    Skips (not fails) when ``google-cloud-vision`` is not installed — the
    fixture is still validated by the JSON-only tests below.
    """
    vision = pytest.importorskip(
        "google.cloud.vision_v1",
        reason="google-cloud-vision is not installed; the real-SDK shape "
        "guard cannot run here (the fixture JSON is still validated)",
    )
    return vision.AnnotateImageResponse.from_json(json.dumps(raw_response))


class TestFixtureIsWellFormed:
    """Guards that do not need the SDK: the fixture is valid and synthetic."""

    def test_fixture_is_valid_json_with_the_expected_top_level_keys(self, raw_response):
        assert "fullTextAnnotation" in raw_response
        assert "context" in raw_response

    def test_context_records_the_source_page(self, raw_response):
        ctx = raw_response["context"]
        assert ctx["pageNumber"] == 1

    def test_fixture_carries_only_the_synthetic_invoice_text(self, raw_response):
        text = raw_response["fullTextAnnotation"]["text"]
        assert EXPECTED_TEXT in text
        assert EXPECTED_SUPPLIER in text
        # Nothing beyond the two synthetic lines.
        assert len([ln for ln in text.splitlines() if ln.strip()]) == 2

    def test_fixture_is_small(self):
        # A fixture that grows without bound stops being reviewable.
        assert FIXTURE.stat().st_size < 64 * 1024


class TestRealSdkRoundTrip:
    """The payload must survive the real SDK's JSON parser."""

    def test_response_parses_into_the_sdk_type(self, parsed_response):
        full = getattr(parsed_response, "full_text_annotation", None)
        assert full is not None
        assert full.text

    def test_page_count_and_dimensions_survive(self, parsed_response):
        full = parsed_response.full_text_annotation
        pages = list(full.pages)
        assert len(pages) >= 1
        page = pages[0]
        assert float(page.width) > 0
        assert float(page.height) > 0

    def test_blocks_and_paragraphs_survive(self, parsed_response):
        full = parsed_response.full_text_annotation
        blocks = [b for p in full.pages for b in p.blocks]
        assert len(blocks) >= 1
        words = [w for b in blocks for para in b.paragraphs for w in para.words]
        assert len(words) >= 1

    def test_every_word_has_symbols_and_a_bounding_box(self, parsed_response):
        full = parsed_response.full_text_annotation
        for page in full.pages:
            for block in page.blocks:
                for para in block.paragraphs:
                    for word in para.words:
                        assert word.symbols
                        bb = word.bounding_box
                        has_geometry = bool(len(bb.vertices) >= 4) or bool(
                            len(bb.normalized_vertices) >= 4
                        )
                        assert has_geometry, (
                            "Vision word lost both coordinate systems "
                            "(vertices / normalized_vertices) — the engine "
                            "would drop every word"
                        )


class TestEngineConsumesTheRealPayload:
    """The engine's own code paths must turn this payload into blocks.

    This is the real drift guard: it exercises
    ``google_engine._page_dims`` and ``google_engine._blocks_from_page``
    on the recorded response, exactly as ``_aggregate_json_results`` does.
    """

    def test_page_dims_reads_vision_page_dimensions(self, parsed_response):
        from nc_ocr_flow.engines import google_engine

        full = parsed_response.full_text_annotation
        width, height = google_engine._page_dims(full, parsed_response)
        assert width > 0 and height > 0

    def test_blocks_are_produced_from_the_real_payload(self, parsed_response):
        from nc_ocr_flow.engines import google_engine

        full = parsed_response.full_text_annotation
        width, height = google_engine._page_dims(full, parsed_response)
        blocks = google_engine._blocks_from_page(full, width, height, 0)
        assert blocks, "the engine produced no blocks from a real Vision response"
        assert all(b.text.strip() for b in blocks)
        joined = " ".join(b.text for b in blocks)
        assert EXPECTED_TEXT.split()[0] in joined
        assert "GmbH" in joined

    def test_annotations_report_text(self, parsed_response):
        from nc_ocr_flow.engines import google_engine

        assert google_engine._annotation_has_text(parsed_response.full_text_annotation)
