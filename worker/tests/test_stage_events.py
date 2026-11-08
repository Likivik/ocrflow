"""The stage events the worker reports travel inside the finish payload.

Two properties matter and neither is visible from the app side unless it is
pinned here:

* the payload carries the events on success *and* on failure -- a job that
  broke halfway is exactly the one whose stages explain why;
* the key is absent when there is nothing to say, so an older app sees a
  payload it understands and a job that ran no stage does not claim an
  empty run.
"""

from __future__ import annotations

import json

from nc_ocr_flow.queue_client import QueueClient
from nc_ocr_flow.runner import run_once
from nc_ocr_flow.stage_events import (
    STAGES,
    StageCollector,
    StageTimer,
    collector_scope,
)

BASE = "https://cloud.example"


class FakeResponse:
    def __init__(self, status_code: int, body: object = None) -> None:
        self.status_code = status_code
        self._body = body
        import json as _json

        self.text = _json.dumps(body)

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


def test_the_vocabulary_is_the_seven_stages():
    assert STAGES == (
        "intake",
        "inspect",
        "classify",
        "prepare",
        "recognize",
        "compose",
        "deliver",
    )


def test_the_success_payload_carries_the_stage_events():
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "done"})),
        ]
    )

    def process(path, file_id, **_):
        with StageTimer("intake"):
            pass
        with StageTimer("recognize") as timer:
            timer.cost_pages = 3
        return {"engine": "google", "pages": 3, "chars": 10}

    assert run_once(c, process) is True

    payload = session.calls[1]["data"]
    events = json.loads(payload["stage_events"])
    assert [e["stage"] for e in events] == ["intake", "recognize"]
    assert events[1]["cost_pages"] == 3
    assert events[0]["cost_pages"] is None
    assert all(e["duration_ms"] is not None for e in events)


def test_the_failure_payload_carries_the_stage_events():
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9})),
        ]
    )

    def process(path, file_id, **_):
        with StageTimer("intake"):
            pass
        with StageTimer("recognize"):
            raise RuntimeError("vision quota exhausted")

    assert run_once(c, process) is True

    payload = session.calls[1]["data"]
    events = json.loads(payload["stage_events"])
    assert [e["stage"] for e in events] == ["intake", "recognize"]
    # The stage that blew up carries the failure code, so the row says which
    # stage failed rather than only that something did.
    assert events[-1]["reason_code"] == "unknown"


def test_the_key_is_absent_when_no_stage_ran():
    c, session = client(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "done"})),
        ]
    )

    assert run_once(c, lambda path, file_id, **_: {"engine": "google"}) is True
    assert "stage_events" not in session.calls[1]["data"]


def test_an_unknown_stage_name_is_dropped_with_a_warning(caplog):
    collector = StageCollector()
    with caplog.at_level("WARNING"):
        assert collector.record("not_a_stage") is None
    assert collector.events == []
    assert "not_a_stage" in caplog.text


def test_cost_pages_only_counts_for_the_recognize_stage():
    collector = StageCollector()
    collector.record("compose", cost_pages=5)
    assert collector.events[0].cost_pages is None

    collector.record("recognize", cost_pages=5)
    assert collector.events[1].cost_pages == 5


def test_the_collector_is_visible_to_the_code_that_runs_the_pipeline():
    collector = StageCollector()
    with collector_scope(collector), StageTimer("intake"):
        pass
    assert [e.stage for e in collector.events] == ["intake"]
