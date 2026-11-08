"""The classifier's verdicts must be reported as codes, not as prose.

The app records decisions in ``stage_events`` with a reason code from a closed
vocabulary. The classifier produces three verdicts -- the file already has a
text layer, the image is a photo, our own earlier OCR output is there -- and
each used to be reported as free text only. A skip and a failure then looked
the same in the table, and ``GROUP BY stage, reason_code`` could not tell
"we decided not to" from "we tried and broke".

The classifier logic is untouched: only what is reported changes.
"""

from __future__ import annotations

import json

from nc_ocr_flow.queue_client import QueueClient
from nc_ocr_flow.runner import run_once
from nc_ocr_flow.stage_events import StageTimer, collector_scope

BASE = "https://cloud.example"


class FakeResponse:
    def __init__(self, status_code: int, body: object = None) -> None:
        self.status_code = status_code
        self._body = body
        self.text = json.dumps(body)

    def json(self) -> object:
        if self._body is None:
            raise ValueError("no json")
        return self._body


class FakeSession:
    def __init__(self, responses: list[FakeResponse]) -> None:
        self._responses = list(responses)
        self.calls: list[dict] = []

    def post(self, url, data=None, headers=None, timeout=None):
        self.calls.append({"url": url, "data": data, "headers": headers or {}})
        if not self._responses:
            raise AssertionError(f"unexpected call to {url}")
        return self._responses.pop(0)


def envelope(data: object) -> dict:
    return {"ocs": {"meta": {"status": "ok", "statuscode": 200}, "data": data}}


def client(responses: list[FakeResponse]) -> tuple[QueueClient, FakeSession]:
    session = FakeSession(responses)
    return QueueClient(BASE, "s3cret", session=session), session


def _skip(reason: str):
    def process(path, file_id, **_):
        with StageTimer("intake"):
            pass
        with StageTimer("classify"):
            pass
        return {"skipped": True, "reason": reason}

    return process


def test_a_born_digital_skip_reports_its_code():
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "skipped"})),
        ]
    )

    assert run_once(c, _skip("born_digital_text_based")) is True

    payload = session.calls[1]["data"]
    assert payload["reason"] == "born_digital_text_based"
    assert payload["reason_code"] == "born_digital_text_based"


def test_a_mobilenet_skip_reports_its_code():
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.png", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "skipped"})),
        ]
    )

    assert run_once(c, _skip("mobilenet")) is True

    payload = session.calls[1]["data"]
    assert payload["reason_code"] == "mobilenet"


def test_a_stamped_good_by_us_skip_reports_its_code():
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "skipped"})),
        ]
    )

    assert run_once(c, _skip("stamped_good_by_us")) is True

    payload = session.calls[1]["data"]
    assert payload["reason_code"] == "stamped_good_by_us"


def test_a_skip_carries_the_code_on_the_stage_event_too():
    """The stage row is where the decision is recorded."""
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "skipped"})),
        ]
    )

    assert run_once(c, _skip("born_digital_text_based")) is True

    payload = session.calls[1]["data"]
    events = json.loads(payload["stage_events"])
    codes = [e["reason_code"] for e in events]
    assert "born_digital_text_based" in codes


def test_an_ordinary_skip_still_reports_its_reason():
    """A skip that is not a classifier verdict keeps its free-text reason."""
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.txt", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "skipped"})),
        ]
    )

    assert run_once(c, _skip("unsupported_ext")) is True

    payload = session.calls[1]["data"]
    assert payload["reason"] == "unsupported_ext"
    assert "reason_code" not in payload
