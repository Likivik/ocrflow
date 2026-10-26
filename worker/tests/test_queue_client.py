"""The worker's half of the contract, tested against the shapes the app really
returns.

Everything here is about the two things that break silently in production: the
OCS envelope (an HTTP 200 can still be a failure) and the secret (a 401 is not a
transient error to retry, it is a human's mistake to fix).
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest
import requests

from nc_ocr_flow.queue_client import (
    Job,
    QueueClient,
    QueueProtocolError,
    WorkerUnauthorized,
)
from nc_ocr_flow.runner import read_secret, run_once

BASE = "https://cloud.example"


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


class FakeSession:
    """Records the calls so the test can assert on headers and payloads."""

    def __init__(self, responses: list[FakeResponse]) -> None:
        self._responses = list(responses)
        self.calls: list[dict] = []

    def post(self, url, data=None, headers=None, timeout=None):
        self.calls.append({"url": url, "data": data, "headers": headers or {}, "timeout": timeout})
        if not self._responses:
            raise AssertionError(f"unexpected call to {url}")
        return self._responses.pop(0)


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


def client(responses: list[FakeResponse]) -> QueueClient:
    return QueueClient(BASE, "s3cret", worker_name="w-1", session=FakeSession(responses))


def test_empty_queue_is_204_and_not_an_error() -> None:
    assert client([FakeResponse(204)]).claim() is None


def test_claim_sends_ocs_and_secret_headers() -> None:
    session = FakeSession([FakeResponse(204)])
    QueueClient(BASE, "s3cret", worker_name="w-1", session=session).claim()

    headers = session.calls[0]["headers"]
    assert headers["OCS-APIRequest"] == "true"
    assert headers["X-OcrFlow-Secret"] == "s3cret"
    assert headers["X-OcrFlow-Worker"] == "w-1"
    assert session.calls[0]["url"].endswith("/ocs/v2.php/apps/ocrflow/api/v1/queue/next")


def test_a_camel_case_payload_is_rejected_rather_than_half_read() -> None:
    # The app used to emit `fileId`. `.get('file_id')` returned None and the job
    # looked fine — with the uniqueness key silently gone. A missing identity
    # field must be a protocol error.
    body = envelope({"id": 7, "path": "/u/files/a.pdf", "fileId": 42, "etag": "abc"})
    with pytest.raises(QueueProtocolError, match="cannot read"):
        client([FakeResponse(200, body)]).claim()


def test_a_null_file_id_is_allowed_because_some_rows_have_none() -> None:
    body = envelope({"id": 7, "path": "/u/files/a.pdf", "file_id": None, "etag": None})
    job = client([FakeResponse(200, body)]).claim()
    assert job is not None
    assert job.file_id is None


def test_claim_parses_the_job_out_of_the_envelope() -> None:
    body = envelope(
        {
            "id": 7,
            "path": "/u/files/a.pdf",
            "file_id": 42,
            "etag": "abc",
            "size": 1024,
            "attempts": 2,
        }
    )
    job = client([FakeResponse(200, body)]).claim()

    assert isinstance(job, Job)
    assert (job.id, job.path, job.file_id, job.etag, job.size, job.attempts) == (
        7,
        "/u/files/a.pdf",
        42,
        "abc",
        1024,
        2,
    )


def test_a_200_with_a_failure_statuscode_is_a_protocol_error() -> None:
    # The whole reason this module exists: OCS says 200 at the HTTP layer and
    # "failure" in the body. Treating that as success would silently lose jobs.
    body = envelope(None, statuscode=404, message="no such job")
    with pytest.raises(QueueProtocolError, match="404"):
        client([FakeResponse(200, body)]).claim()


def test_non_json_is_a_protocol_error_that_names_the_likely_cause() -> None:
    with pytest.raises(QueueProtocolError, match="OCS-APIRequest"):
        client([FakeResponse(200, None, text="<html>login</html>")]).claim()


def test_a_rejected_secret_is_fatal_not_retryable() -> None:
    with pytest.raises(WorkerUnauthorized, match="php-fpm"):
        client([FakeResponse(401)]).claim()


def test_result_sends_only_the_fields_it_has() -> None:
    session = FakeSession([FakeResponse(200, envelope({"id": 7, "status": "done"}))])
    QueueClient(BASE, "s3cret", session=session).result(
        7, status="done", engine="google", pages=3, chars=1000, stamp_version=None
    )

    data = session.calls[0]["data"]
    assert data == {"status": "done", "engine": "google", "pages": 3, "chars": 1000}
    assert session.calls[0]["url"].endswith("/queue/7/result")


def test_failure_truncates_a_long_error_to_what_the_column_holds() -> None:
    session = FakeSession([FakeResponse(200, envelope({"id": 7}))])
    QueueClient(BASE, "s3cret", session=session).failure(7, "x" * 2000, reason="worker_error")

    assert len(session.calls[0]["data"]["error"]) == 500
    assert session.calls[0]["data"]["reason"] == "worker_error"


# -- runner -------------------------------------------------------------


def test_run_once_reports_a_skipped_file_as_skipped_not_done() -> None:
    session = FakeSession(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9, "status": "skipped"})),
        ]
    )
    c = QueueClient(BASE, "s3cret", session=session)

    handled = run_once(c, lambda path, file_id: {"skipped": True, "reason": "born_digital"})

    assert handled is True
    assert session.calls[1]["data"]["status"] == "skipped"
    assert session.calls[1]["data"]["reason"] == "born_digital"


def test_run_once_reports_a_raising_engine_as_a_failure() -> None:
    session = FakeSession(
        [
            FakeResponse(
                200, envelope({"id": 9, "path": "/u/files/a.pdf", "file_id": 1, "etag": "e1"})
            ),
            FakeResponse(200, envelope({"id": 9})),
        ]
    )
    c = QueueClient(BASE, "s3cret", session=session)

    def boom(path, file_id):
        raise RuntimeError("vision quota exhausted")

    assert run_once(c, boom) is True
    assert session.calls[1]["data"]["reason"] == "worker_error"
    assert "vision quota exhausted" in session.calls[1]["data"]["error"]


def test_run_once_does_nothing_on_an_empty_queue() -> None:
    session = FakeSession([FakeResponse(204)])
    assert run_once(QueueClient(BASE, "s3cret", session=session), lambda p, f: {}) is False
    assert len(session.calls) == 1


# -- config -------------------------------------------------------------


def test_secret_prefers_the_credential_file_over_the_environment(tmp_path: Path) -> None:
    (tmp_path / "ocrflow-worker-secret").write_text("from-file\n", encoding="utf-8")
    env = {"CREDENTIALS_DIRECTORY": str(tmp_path)}
    assert read_secret(env) == "from-file"


def test_secret_falls_back_to_the_environment(tmp_path: Path) -> None:
    env = {"NC_OCR_WORKER_SECRET": " from-env ", "CREDENTIALS_DIRECTORY": str(tmp_path)}
    assert read_secret(env) == "from-env"


def test_an_empty_secret_is_refused_at_construction() -> None:
    with pytest.raises(ValueError, match="empty"):
        QueueClient(BASE, "")
