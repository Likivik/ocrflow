"""The claim token, the worker's half.

The token is minted by the app at claim time and lives in this process's
memory for exactly one job. Everything here pins the contract that replaces
the old silent discard: every call that acts on a claimed job carries the
token, and a 409 means "this claim is gone" — stop work, do not retry, do
not report a failure (the report itself would be refused).
"""

from __future__ import annotations

import json
import threading
from pathlib import Path

import pytest
import requests

from nc_ocr_flow.queue_client import (
    ClaimStale,
    Job,
    QueueClient,
    QueueProtocolError,
)
from nc_ocr_flow.runner import run_once

BASE = "https://cloud.example"
TOKEN = "a" * 64


class FakeResponse:
    def __init__(self, status_code: int, body: object = None, *, text: str | None = None) -> None:
        self.status_code = status_code
        self._body = body
        self.text = text if text is not None else json.dumps(body)
        self.request = requests.Request("POST", f"{BASE}/ocs").prepare()

    def json(self) -> object:
        if self._body is None:
            raise json.JSONDecodeError("no json", self.text, 0)
        return self._body

    def iter_content(self, chunk_size: int = 8192):
        yield b"pdf-bytes"


class FakeSession:
    """Records every verb so tests assert on headers, not on vibes."""

    def __init__(self, responses: list[FakeResponse]) -> None:
        self._responses = list(responses)
        self.calls: list[dict] = []

    def _next(self, url, **kwargs):
        self.calls.append({"url": url, **kwargs})
        if not self._responses:
            raise AssertionError(f"unexpected call to {url}")
        return self._responses.pop(0)

    def post(self, url, data=None, headers=None, timeout=None, params=None):
        return self._next(url, data=data, headers=headers or {}, timeout=timeout, params=params)

    def get(self, url, headers=None, timeout=None, stream=False):
        return self._next(url, headers=headers or {}, timeout=timeout, stream=stream)

    def put(self, url, data=None, headers=None, timeout=None):
        return self._next(url, data=data, headers=headers or {}, timeout=timeout)


def envelope(data: object, statuscode: int = 200, message: str = "") -> dict:
    return {
        "ocs": {
            "meta": {
                "status": "ok" if statuscode == 200 else "failure",
                "statuscode": statuscode,
                "message": message,
            },
            "data": data,
        }
    }


def claimed_job() -> dict:
    return {
        "id": 7,
        "path": "/u/files/a.pdf",
        "file_id": 42,
        "etag": "abc",
        "size": 1024,
        "attempts": 1,
        "claim_token": TOKEN,
    }


def client(responses: list[FakeResponse], **kwargs) -> QueueClient:
    return QueueClient(BASE, "s3cret", session=FakeSession(responses), **kwargs)


def session_of(c: QueueClient) -> FakeSession:
    assert isinstance(c.session, FakeSession)
    return c.session


def test_the_claim_response_carries_the_token() -> None:
    c = client([FakeResponse(200, envelope(claimed_job()))])
    job = c.claim()
    assert job is not None
    assert job.claim_token == TOKEN


def test_fetch_file_sends_the_claim_header() -> None:
    c = client([FakeResponse(200)])
    c.fetch_file(7, Path("/tmp/x.pdf"), claim=TOKEN)

    headers = session_of(c).calls[0]["headers"]
    assert headers["X-OcrFlow-Claim"] == TOKEN


def test_result_and_failure_send_the_claim_header() -> None:
    c = client([FakeResponse(200, envelope({"id": 7})), FakeResponse(200, envelope({"id": 7}))])
    c.result(7, status="done", claim=TOKEN)
    c.failure(7, "boom", reason="worker_error", claim=TOKEN)

    assert session_of(c).calls[0]["headers"]["X-OcrFlow-Claim"] == TOKEN
    assert session_of(c).calls[1]["headers"]["X-OcrFlow-Claim"] == TOKEN


def test_a_409_from_result_is_claim_stale_not_a_protocol_error() -> None:
    c = client([FakeResponse(409, envelope({"reason_code": "error_worker_job_reference_stale"}))])
    with pytest.raises(ClaimStale, match="error_worker_job_reference_stale"):
        c.result(7, status="done", claim=TOKEN)


def test_a_409_from_fetch_file_is_claim_stale() -> None:
    c = client([FakeResponse(409, envelope({"reason_code": "error_worker_job_reference_stale"}))])
    with pytest.raises(ClaimStale):
        c.fetch_file(7, Path("/tmp/x.pdf"), claim=TOKEN)


def test_a_409_from_failure_is_claim_stale() -> None:
    c = client([FakeResponse(409, envelope({"reason_code": "error_worker_job_reference_stale"}))])
    with pytest.raises(ClaimStale):
        c.failure(7, "boom", reason="worker_error", claim=TOKEN)


def test_registration_returns_and_keeps_the_worker_id() -> None:
    c = client([FakeResponse(200, envelope({"id": 11}))])
    assert c.register("0.4.0") == 11
    assert session_of(c).calls[0]["url"].endswith("/worker/register")
    assert session_of(c).calls[0]["data"] == {"version": "0.4.0"}


def test_heartbeat_sends_the_worker_id_and_reports_an_unknown_row() -> None:
    c = client(
        [
            FakeResponse(200, envelope({"id": 11})),
            FakeResponse(200, envelope({"id": 11, "seen": True})),
        ]
    )
    c.register("0.4.0")

    ok = c.heartbeat()
    assert ok is True
    assert session_of(c).calls[1]["url"].endswith("/worker/heartbeat")
    assert session_of(c).calls[1]["headers"]["X-OcrFlow-Worker-Id"] == "11"

    # A pruned row answers 404: the caller must register again, not spin.
    gone = client(
        [
            FakeResponse(200, envelope({"id": 11})),
            FakeResponse(404, envelope(None, statuscode=404, message="unknown worker")),
        ]
    )
    gone.register("0.4.0")
    assert gone.heartbeat() is False


def test_the_heartbeat_thread_registers_once_and_beats() -> None:
    import time

    from nc_ocr_flow.runner import start_heartbeat

    c = client(
        [
            FakeResponse(200, envelope({"id": 11})),
            FakeResponse(200, envelope({"id": 11})),
            FakeResponse(200, envelope({"id": 11})),
        ]
    )
    stop = start_heartbeat(c, seconds=0)
    try:
        deadline = time.monotonic() + 5
        while len(session_of(c).calls) < 3 and time.monotonic() < deadline:
            time.sleep(0.01)
    finally:
        stop.set()
    assert session_of(c).calls[0]["url"].endswith("/worker/register")
    assert session_of(c).calls[1]["url"].endswith("/worker/heartbeat")
    assert session_of(c).calls[2]["url"].endswith("/worker/heartbeat")


def test_run_once_passes_the_token_into_process_and_result() -> None:
    c = client(
        [
            FakeResponse(200, envelope(claimed_job())),
            FakeResponse(200, envelope({"id": 7, "status": "done"})),
        ]
    )
    seen: dict[str, object] = {}

    def process(path, file_id, **kwargs):
        seen.update(kwargs)
        return {"engine": "tesseract"}

    assert run_once(c, process) is True
    assert seen.get("claim_token") == TOKEN
    assert session_of(c).calls[1]["headers"]["X-OcrFlow-Claim"] == TOKEN


def test_run_once_abandons_the_job_when_the_claim_is_stale() -> None:
    # The engine reports its result; the app answers 409 — the claim was
    # requeued or finished elsewhere. The worker must NOT retry and must NOT
    # report a failure: this job is no longer its to talk about.
    c = client(
        [
            FakeResponse(200, envelope(claimed_job())),
            FakeResponse(409, envelope({"reason_code": "error_worker_job_reference_stale"})),
        ]
    )

    assert run_once(c, lambda p, f, **_: {"engine": "tesseract"}) is True
    assert len(session_of(c).calls) == 2, "no failure report after a stale claim"


def test_run_once_treats_a_stale_claim_during_processing_as_done() -> None:
    # The token can go stale mid-job too (fetch refused). Abandon, no retry.
    c = client([FakeResponse(200, envelope(claimed_job()))])

    def boom(path, file_id, **kwargs):
        raise ClaimStale("claim gone", status_code=409)

    assert run_once(c, boom) is True
    assert len(session_of(c).calls) == 1, "no failure report for an abandoned claim"


def test_a_stale_claim_is_not_classified_as_retryable() -> None:
    from nc_ocr_flow.runner import classify_failure

    assert classify_failure(ClaimStale("claim gone", status_code=409)) == (
        "error_worker_job_reference_stale"
    )
