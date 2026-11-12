"""The queue row must say how big the job was.

`runner._report` sends `pages=` and `chars=` from the engine's outcome, but no
success return in `webhook_server` ever set those keys — so every row in
production stored NULL for both, and the panel had nothing to sum. These pin the
two halves that were missing: the measurement, and the fact that the runner
forwards it.
"""

from __future__ import annotations

import pymupdf
import pytest

from nc_ocr_flow import runner, webhook_server


# Cyrillic on purpose: these tests assert a layer a Latin-only font cannot
# produce, so the ambiguous-character lint (RUF001) is noise here.
def _pdf_with_text(path, pages: int, text: str = "СЧЁТ НА ОПЛАТУ 2026"):  # noqa: RUF001
    doc = pymupdf.open()
    for _ in range(pages):
        page = doc.new_page()
        page.insert_text((72, 72), text, fontsize=12)
    doc.save(str(path))
    doc.close()
    return path


class TestPageStats:
    def test_counts_pages_and_characters_of_the_output(self, tmp_path):
        pdf = _pdf_with_text(tmp_path / "out.pdf", pages=3)

        stats = webhook_server._page_stats_keys(pdf)

        assert stats["pages"] == 3
        assert stats["chars"] >= 3 * len("СЧЁТ НА ОПЛАТУ 2026") - 3  # noqa: RUF001

    def test_a_missing_file_is_not_a_job_failure(self, tmp_path):
        # Reporting must never turn a finished job into an error: the PDF is
        # produced before this runs, but a broken path here would lose the
        # result the user already paid for.
        assert webhook_server._page_stats_keys(tmp_path / "absent.pdf") == {}

    def test_a_file_that_is_not_a_pdf_is_not_a_job_failure(self, tmp_path):
        junk = tmp_path / "not-a.pdf"
        junk.write_bytes(b"not a pdf at all")

        assert webhook_server._page_stats_keys(junk) == {}


class TestTheRunnerForwardsThem:
    def test_report_sends_pages_and_chars(self):
        calls = {}

        class Client:
            def result(self, job_id, **kwargs):
                calls["id"] = job_id
                calls.update(kwargs)

        job = type("Job", (), {"id": 7, "claim_token": None})()

        runner._report(
            Client(),
            job,
            {"engine": "google", "pages": 4, "chars": 5402},
        )

        # The keys the app writes into ocrflow_jobs.pages / .chars.
        assert calls["pages"] == 4
        assert calls["chars"] == 5402
        assert calls["status"] == "done"

    def test_a_skip_reports_no_size(self):
        calls = {}

        class Client:
            def result(self, job_id, **kwargs):
                calls.update(kwargs)

        job = type("Job", (), {"id": 7, "claim_token": None})()

        runner._report(Client(), job, {"skipped": True, "reason": "born_digital_text_based"})

        assert calls["status"] == "skipped"
        assert "pages" not in calls


@pytest.mark.parametrize("pages", [1, 4])
def test_page_stats_scale_with_the_document(tmp_path, pages):
    assert (
        webhook_server._page_stats_keys(_pdf_with_text(tmp_path / "o.pdf", pages))["pages"] == pages
    )
