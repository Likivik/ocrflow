"""The lease, the worker's half.

A claim used to be a timestamp plus a heartbeat. Three live rows showed what that
misses: the worker was healthy and heartbeating, the OCR had finished, the report
was lost to a 502 during a deploy, and the row stayed `claimed` for eleven hours
because nothing could tell "alive" from "still working on this".

A lease the holder renews answers that, and a report that retries answers the
lost 502. These tests pin both halves.
"""

from __future__ import annotations

import json

import pytest
import requests

from nc_ocr_flow.queue_client import ClaimStale, Job, QueueClient, QueueProtocolError
from nc_ocr_flow.runner import run_once

BASE = "https://cloud.example"
TOKEN = "b" * 64


class FakeResponse:
    def __init__(self, status_code: int, body: object = None) -> None:
        self.status_code = status_code
        self._body = body
        self.text = json.dumps(body) if body is not None else ""
        self.request = requests.Request("POST", f"{BASE}/ocs").prepare()

    def json(self) -> object:
        if self._body is None:
            raise json.JSONDecodeError("no json", self.text, 0)
        return self._body


class FakeSession:
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


def envelope(data: object, statuscode: int = 200) -> dict:
    return {
        "ocs": {
            "meta": {"status": "ok" if statuscode == 200 else "failure", "statuscode": statuscode},
            "data": data,
        }
    }


def client(responses: list[FakeResponse]) -> QueueClient:
    return QueueClient(BASE, "s3cret", session=FakeSession(responses))


def session_of(c: QueueClient) -> FakeSession:
    assert isinstance(c.session, FakeSession)
    return c.session


@pytest.fixture(autouse=True)
def no_backoff(monkeypatch):
    """The retry delays are for a real network, not for a test run."""
    monkeypatch.setattr("nc_ocr_flow.queue_client.time.sleep", lambda _s: None)


def test_renew_extends_the_lease_with_the_claim_token() -> None:
    c = client([FakeResponse(200, envelope({"id": 7, "renewed": True}))])

    assert c.renew(7, TOKEN) is True
    call = session_of(c).calls[0]
    assert call["url"].endswith("/queue/7/renew")
    assert call["headers"]["X-OcrFlow-Claim"] == TOKEN


def test_a_lost_lease_answers_false_not_an_exception() -> None:
    # 409 means recovery took the claim back. The worker must stop, and the
    # caller must not have to distinguish that from a crash.
    c = client(
        [FakeResponse(409, envelope({"reason_code": "error_worker_job_reference_stale"}, 409))]
    )

    assert c.renew(7, TOKEN) is False


def test_release_puts_the_claim_back() -> None:
    c = client([FakeResponse(200, envelope({"id": 7, "released": True}))])

    assert c.release(7, TOKEN) is True
    assert session_of(c).calls[0]["url"].endswith("/queue/7/release")


def test_a_5xx_on_a_report_is_retried_not_swallowed() -> None:
    # The exact production failure: one 500 and the report was gone forever.
    c = client(
        [
            FakeResponse(500),
            FakeResponse(500),
            FakeResponse(200, envelope(None)),
        ]
    )

    c.result(7, claim=TOKEN, status="done")

    assert len(session_of(c).calls) == 3


def test_a_persistent_5xx_still_ends_as_an_error() -> None:
    c = client([FakeResponse(500) for _ in range(4)])

    with pytest.raises(QueueProtocolError):
        c.result(7, claim=TOKEN, status="done")


def test_a_409_is_not_retried() -> None:
    c = client(
        [FakeResponse(409, envelope({"reason_code": "error_worker_job_reference_stale"}, 409))]
    )

    with pytest.raises(ClaimStale):
        c.failure(7, error="boom", claim=TOKEN)

    assert len(session_of(c).calls) == 1, "a gone claim must not be reported again"


def test_an_unreportable_failure_releases_the_claim() -> None:
    """The eleven-hour row: OCR finished, every report got 502, claim stuck."""
    claim = {
        "id": 7,
        "path": "/u/files/a.pdf",
        "file_id": 42,
        "etag": "abc",
        "size": 1024,
        "attempts": 1,
        "claim_token": TOKEN,
    }
    c = client(
        [FakeResponse(200, envelope(claim))]
        + [FakeResponse(500) for _ in range(4)]
        + [FakeResponse(200, envelope({"id": 7, "released": True}))]
    )

    def process(*_a, **_k):
        raise ValueError("engine exploded")

    assert run_once(c, process) is True

    urls = [call["url"] for call in session_of(c).calls]
    assert any(u.endswith("/queue/next") for u in urls)
    assert any(u.endswith("/queue/7/failure") for u in urls)
    assert urls[-1].endswith("/queue/7/release"), "a claim nobody can report must go back"
