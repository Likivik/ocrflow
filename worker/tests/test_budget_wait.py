"""The daily page budget, the worker's half.

Money leaves when the engine runs, so the app books a run at claim and answers
``wait: budget`` when the day is spent. The worker's job on that answer is to
do exactly nothing: no attempt is burned, no page can be spent past the cap,
and the wait is a sleep — not a busy retry loop and not a give-up.
"""

from __future__ import annotations

import json

import requests

from nc_ocr_flow.queue_client import BudgetWait, QueueClient
from nc_ocr_flow.runner import BUDGET_WAIT_SECONDS, run_once

BASE = "https://cloud.example"


# The worker unwraps an OCS envelope (`{"ocs": {"data": …}}`) — a test
# payload must look like what really arrives on the wire.
class FakeResponse:
    def __init__(self, status_code: int, body: object = None) -> None:
        self.status_code = status_code
        self._body = body
        self.text = json.dumps(body)
        self.request = requests.Request("POST", f"{BASE}/ocs").prepare()

    def json(self) -> object:
        return self._body


class FakeSession:
    def __init__(self, *responses: FakeResponse) -> None:
        self._responses = list(responses)
        self.calls = 0

    def post(self, url: str, **kwargs: object) -> FakeResponse:
        self.calls += 1
        return self._responses.pop(0)


def make_client(session: FakeSession) -> QueueClient:
    return QueueClient(
        base_url=BASE,
        secret="s" * 32,
        worker_name="w",
        session=session,
    )


def test_claim_answers_budget_wait_when_the_day_is_spent() -> None:
    session = FakeSession(
        FakeResponse(200, {"ocs": {"data": {"wait": "budget", "message": "spent"}}}),
    )
    job = make_client(session).claim()

    assert isinstance(job, BudgetWait)
    assert session.calls == 1


def test_an_empty_queue_is_still_none_not_a_budget_wait() -> None:
    session = FakeSession(FakeResponse(204, None))
    job = make_client(session).claim()

    assert job is None


def test_a_budget_wait_burns_no_attempt_and_sleeps(monkeypatch) -> None:
    session = FakeSession(FakeResponse(200, {"ocs": {"data": {"wait": "budget"}}}))
    client = make_client(session)

    slept: list[float] = []
    monkeypatch.setattr("nc_ocr_flow.runner.time.sleep", slept.append)

    def never_called(**kwargs: object) -> dict:
        raise AssertionError("a budget wait must never reach the pipeline")

    handled = run_once(client, never_called)

    assert handled is False, "nothing was handled: the job stays queued"
    assert slept == [BUDGET_WAIT_SECONDS], "the wait is one sleep, not a busy retry"
