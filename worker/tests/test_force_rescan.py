"""The operator's "rescan anyway" bit must survive the whole trip.

The worker has always understood ``force``: ``process(..., force=True)`` returns
before every skip decision (stamp-by-us, born-digital, someone else's OCR
layer). What was missing was a carrier. Without the bit arriving, a forced job
is skipped again by the exact decision the operator was overriding — the redo
is billed nothing and fixes nothing, and the row comes back with the same
verdict it had before, which is indistinguishable from "the button did
nothing".

Two links, both pinned here:

1. the claim payload -> ``Job.force`` (``from_api``), including the older app
   build that sends no such key at all;
2. ``run_once`` -> ``process(force=...)``, the hop where a dropped keyword
   would silently turn a forced run into an ordinary one.
"""

from __future__ import annotations

from typing import Any

from nc_ocr_flow.queue_client import Job
from nc_ocr_flow.runner import run_once

BASE_PAYLOAD: dict[str, Any] = {
    "id": 41,
    "path": "alice/files/scan.pdf",
    "file_id": 7,
    "etag": "abc123",
    "size": 1024,
    "attempts": 0,
    "engine": "google",
    "claim_token": "t" * 64,
}


def test_a_forced_claim_is_read_as_forced() -> None:
    job = Job.from_api({**BASE_PAYLOAD, "force": True})
    assert job.force is True


def test_an_ordinary_claim_is_not_forced() -> None:
    assert Job.from_api(BASE_PAYLOAD).force is False
    # An app build that predates the column sends nothing at all: absent must
    # mean "ordinary scan", never "force" and never a crash.
    assert Job.from_api({**BASE_PAYLOAD, "force": None}).force is False


class _Client:
    """One claim, then the queue is empty."""

    def __init__(self, job: Job) -> None:
        self._job = job
        self.failures: list[int] = []

    def claim(self) -> Job | None:
        job, self._job = self._job, None
        return job

    def failure(self, job_id: int, **_: Any) -> None:
        self.failures.append(job_id)

    def result(self, job_id: int, **_: Any) -> None:
        return None


def _run(force: bool) -> dict[str, Any]:
    captured: dict[str, Any] = {}

    def process(path: str, file_id: int, **kwargs: Any) -> dict[str, Any]:
        captured.update(kwargs)
        captured["path"] = path
        return {"status": "done", "pages": 1, "chars": 10}

    client = _Client(Job.from_api({**BASE_PAYLOAD, "force": force}))
    assert run_once(client, process) is True  # type: ignore[arg-type]
    return captured


def test_the_force_bit_reaches_the_pipeline() -> None:
    assert _run(True)["force"] is True


def test_an_ordinary_job_still_reaches_the_pipeline_unforced() -> None:
    assert _run(False)["force"] is False
