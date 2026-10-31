"""fetch_file: the endpoint that replaces the WebDAV download.

The worker holds no Nextcloud identity, so these cases are the whole transport
contract: the app either streams the bytes, or says the file is gone, or rejects
the secret. Anything else is a protocol failure and must not be silently turned
into an empty document.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from nc_ocr_flow.queue_client import (
    FileGone,
    QueueClient,
    QueueProtocolError,
    WorkerUnauthorized,
)


class FakeResponse:
    def __init__(self, status_code: int, chunks: list[bytes] | None = None) -> None:
        self.status_code = status_code
        self._chunks = chunks or []

    def iter_content(self, chunk_size: int = 0):  # noqa: ARG002 - signature parity
        yield from self._chunks


class FakeSession:
    def __init__(self, response: FakeResponse) -> None:
        self._response = response
        self.calls: list[tuple[str, dict]] = []

    def get(self, url: str, **kwargs):
        self.calls.append((url, kwargs))
        return self._response


def _client(session: FakeSession) -> QueueClient:
    return QueueClient(base_url="http://nc", secret="s3cret", worker_name="w1", session=session)


def test_streams_bytes_to_the_destination(tmp_path: Path) -> None:
    session = FakeSession(FakeResponse(200, [b"%PDF-1.7\n", b"rest of the file"]))
    dest = tmp_path / "sub" / "job-7.pdf"

    returned = _client(session).fetch_file(7, dest)

    assert returned == dest
    assert dest.read_bytes() == b"%PDF-1.7\nrest of the file"


def test_sends_the_secret_and_ocs_header(tmp_path: Path) -> None:
    session = FakeSession(FakeResponse(200, [b"x"]))

    _client(session).fetch_file(1, tmp_path / "f.pdf")

    url, kwargs = session.calls[0]
    assert url == "http://nc/ocs/v2.php/apps/ocrflow/api/v1/queue/1/file"
    headers = {k.lower(): v for k, v in kwargs["headers"].items()}
    assert headers["x-ocrflow-secret"] == "s3cret"
    assert headers["x-ocrflow-worker"] == "w1"
    assert headers["ocs-apirequest"] == "true"
    assert kwargs["stream"] is True


def test_missing_file_is_its_own_error(tmp_path: Path) -> None:
    # 404 means "moved or deleted since it was queued": retrying cannot help, so
    # it must be distinguishable from a broken server.
    with pytest.raises(FileGone):
        _client(FakeSession(FakeResponse(404))).fetch_file(3, tmp_path / "f.pdf")


def test_rejected_secret_is_not_a_retry(tmp_path: Path) -> None:
    with pytest.raises(WorkerUnauthorized):
        _client(FakeSession(FakeResponse(401))).fetch_file(3, tmp_path / "f.pdf")


@pytest.mark.parametrize("status", [500, 502, 418])
def test_unexpected_status_is_a_protocol_error(tmp_path: Path, status: int) -> None:
    with pytest.raises(QueueProtocolError):
        _client(FakeSession(FakeResponse(status))).fetch_file(3, tmp_path / "f.pdf")


def test_empty_body_is_refused_and_leaves_nothing_behind(tmp_path: Path) -> None:
    dest = tmp_path / "f.pdf"

    with pytest.raises(QueueProtocolError):
        _client(FakeSession(FakeResponse(200, []))).fetch_file(3, dest)

    assert not dest.exists()
