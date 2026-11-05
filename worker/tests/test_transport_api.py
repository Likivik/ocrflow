"""The api transport: bytes in, bytes out, no Nextcloud identity.

Why this file exists: on `NC_OCR_TRANSPORT=api` the worker is supposed to need
nothing but the shared secret. No test touched that branch, so a mutation that
disabled it (`if TRANSPORT == "api" and job_id is not None:`) left the whole
suite at its baseline result — the entire credential-free path was unproven.

These tests pin the two properties that make the claim true:
  * the bytes move through the app channel in both directions, and
  * no WebDAV function is reached, because reaching one requires an account
    identity the container does not have.
"""

from pathlib import Path

import pytest

from nc_ocr_flow import webhook_server as ws


class _StubQueueClient:
    """Stands in for the app's OCS endpoints; records what it was asked to do."""

    def __init__(self) -> None:
        self.calls: list[tuple] = []
        self.served = b"%PDF-1.7\nserved by the app\n"

    def fetch_file(self, job_id: int, dest: Path) -> Path:
        self.calls.append(("fetch", job_id))
        Path(dest).write_bytes(self.served)
        return Path(dest)

    def put_file(self, job_id: int, source: Path) -> dict:
        self.calls.append(("put", job_id, Path(source).read_bytes()))
        return {"size": len(Path(source).read_bytes()), "etag": "abc"}

    def result_file(self, job_id: int, source: Path, name: str | None = None) -> dict:
        self.calls.append(("result_file", job_id, name, Path(source).read_bytes()))
        return {"path": "/likivik/files/scan.pdf", "name": "scan.pdf", "file_id": 99}


@pytest.fixture()
def api(monkeypatch):
    """Transport = api, with a stub app client and every DAV entry point booby-trapped."""
    stub = _StubQueueClient()
    monkeypatch.setattr(ws, "TRANSPORT", "api")
    monkeypatch.setattr(ws, "_api_client", lambda: stub)

    def _no_dav(*_args, **_kwargs):
        raise AssertionError(
            "a DAV function was reached with transport=api — that is the account "
            "credential this path exists to remove"
        )

    for name in ("_webdav_download", "_webdav_upload", "_webdav_replace_atomic", "_dav_auth"):
        monkeypatch.setattr(ws, name, _no_dav, raising=True)
    return stub


def test_download_comes_from_the_app(api, tmp_path):
    dest = tmp_path / "job.pdf"
    ws._transport_download("/likivik/files/a.pdf", dest, job_id=7)

    assert dest.read_bytes() == api.served
    assert api.calls == [("fetch", 7)]


def test_in_place_result_goes_back_through_the_app(api, tmp_path):
    src = tmp_path / "out.pdf"
    src.write_bytes(b"%PDF-1.7\nOCR text layer\n")

    landed = ws._transport_upload("/likivik/files/a.pdf", src, job_id=7, in_place=True)

    assert landed == "/likivik/files/a.pdf"
    assert api.calls == [("put", 7, b"%PDF-1.7\nOCR text layer\n")]


def test_beside_result_uses_the_name_the_app_assigned(api, tmp_path):
    src = tmp_path / "out.pdf"
    src.write_bytes(b"%PDF-1.7\nfrom an image\n")

    landed = ws._transport_upload("/likivik/files/a.png", src, job_id=7, in_place=False)

    # The app decides the name (collision handling is its job); the worker takes it.
    assert landed == "/likivik/files/scan.pdf"
    assert api.calls[0][0] == "result_file"
    assert api.calls[0][1] == 7


def test_api_transport_refuses_to_guess_a_job(monkeypatch):
    """Without a job id there is no way to ask the app for the right file."""
    monkeypatch.setattr(ws, "TRANSPORT", "api")
    with pytest.raises(RuntimeError, match="job id"):
        ws._transport_download("/likivik/files/a.pdf", Path("/tmp/x.pdf"), job_id=None)
    with pytest.raises(RuntimeError, match="job id"):
        ws._transport_upload("/likivik/files/a.pdf", Path("/tmp/x.pdf"), job_id=None)


def test_no_account_identity_is_available_to_the_dav_path(monkeypatch):
    """The container has no DAV user, so the DAV path must refuse rather than guess.

    This is the production defect that started the whole plan: an unset user used
    to default to a human account name, so the worker silently acted as a person.
    """
    monkeypatch.setattr(ws, "NC_USER", "")
    monkeypatch.setattr(ws, "NC_PASSWORD", "")
    with pytest.raises(RuntimeError, match="no WebDAV credentials"):
        ws._dav_auth()
