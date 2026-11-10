"""The image→PDF source image goes to the trash bin, and the app does it.

The worker used to DELETE the source image over WebDAV right after the
converted PDF was uploaded. A WebDAV DELETE is a permanent removal: it never
reaches the user's trash bin. Measured on production: 23 image conversions,
0 source images left, 0 trash rows — the images were destroyed, not trashed.

Now the worker reports the intent in the result payload and the app moves the
source to the trash through the filesystem API, which is what the trashbin
hooks catch. These tests pin the two halves of that: the outcome carries the
intent, and the runner forwards it to the app.
"""

from __future__ import annotations

from nc_ocr_flow import runner


class _Client:
    """Records what the worker reported to the app."""

    def __init__(self) -> None:
        self.calls: list[dict] = []

    def result(self, job_id, **kwargs):
        self.calls.append({"id": job_id, **kwargs})


def test_the_runner_forwards_the_trash_intent() -> None:
    client = _Client()
    job = type("Job", (), {"id": 7})()

    runner._report(
        client,
        job,
        {
            "engine": "google",
            "pages": 1,
            "chars": 120,
            "source_converted": True,
            "source_path": "/likivik/files/Work/photo.jpg",
            "result_path": "/likivik/files/Work/photo.pdf",
        },
    )

    payload = client.calls[0]
    assert payload["status"] == "done"
    assert payload["source_converted"] == "1"
    assert payload["source_path"] == "/likivik/files/Work/photo.jpg"
    assert payload["result_path"] == "/likivik/files/Work/photo.pdf"


def test_a_job_that_converted_nothing_asks_for_no_trash() -> None:
    client = _Client()
    job = type("Job", (), {"id": 7})()

    runner._report(client, job, {"engine": "google", "pages": 1, "chars": 120})

    payload = client.calls[0]
    assert payload["status"] == "done"
    assert payload.get("source_converted") is None
    assert payload.get("source_path") is None


def test_a_skipped_job_asks_for_no_trash() -> None:
    client = _Client()
    job = type("Job", (), {"id": 7})()

    runner._report(client, job, {"skipped": True, "reason": "source_image_kept"})

    payload = client.calls[0]
    assert payload["status"] == "skipped"
    assert payload.get("source_converted") is None
