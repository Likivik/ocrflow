"""WebDAV credentials: one source order, and no invented default.

The defect these cover was live in production: an unset `NC_OCR_NC_USER` meant the
worker ran as `"likivik"` — a person, with a person's authority over the whole
instance — so a typo in a unit file silently widened the blast radius instead of
stopping the worker.
"""

from __future__ import annotations

import importlib
from pathlib import Path

import pytest

from nc_ocr_flow import webhook_server as ws


@pytest.fixture(autouse=True)
def _restore_module() -> None:
    """Reload the server after each test.

    These tests reload the module with a doctored environment; without this the
    last doctored copy (empty credentials) would be the module every later test
    sees, and 17 unrelated tests failed for exactly that reason before it existed.
    Teardown order matters: `monkeypatch` restores the environment first.
    """
    yield
    importlib.reload(ws)


def _reload(monkeypatch, **env: str) -> ws:
    """Re-import the module with exactly these environment variables."""
    for key in (
        "NC_OCR_DAV_USER",
        "NC_OCR_NC_USER",
        "NC_OCR_NC_PASSWORD",
        "NC_OCR_NC_PASSWORD_FILE",
        "CREDENTIALS_DIRECTORY",
    ):
        monkeypatch.delenv(key, raising=False)
    for key, value in env.items():
        monkeypatch.setenv(key, value)
    return importlib.reload(ws)


def test_no_user_and_no_default(monkeypatch) -> None:
    mod = _reload(monkeypatch, NC_OCR_NC_PASSWORD="pw")
    # The old behaviour: "likivik". The new one: nothing, so a missing variable is
    # a configuration error rather than a policy.
    assert mod.NC_USER == ""
    with pytest.raises(mod.DavCredentialsMissing, match="NC_OCR_DAV_USER"):
        mod._dav_auth()


def test_dav_user_wins_over_the_legacy_name(monkeypatch) -> None:
    mod = _reload(
        monkeypatch,
        NC_OCR_DAV_USER="ocrflow-worker",
        NC_OCR_NC_USER="legacy-user",
        NC_OCR_NC_PASSWORD="pw",
    )
    assert mod._dav_auth() == ("ocrflow-worker", "pw")


def test_the_legacy_name_still_works(monkeypatch) -> None:
    # Nothing breaks for the deployment that is running today.
    mod = _reload(monkeypatch, NC_OCR_NC_USER="likivik", NC_OCR_NC_PASSWORD="pw")
    assert mod._dav_auth() == ("likivik", "pw")


def test_the_credentials_directory_is_preferred(monkeypatch, tmp_path: Path) -> None:
    (tmp_path / "ocrflow-dav-password").write_text("from-credential\n", encoding="utf-8")
    mod = _reload(
        monkeypatch,
        NC_OCR_DAV_USER="ocrflow-worker",
        NC_OCR_NC_PASSWORD="from-env",
        CREDENTIALS_DIRECTORY=str(tmp_path),
    )
    # LoadCredential is the only source that never appears in `systemctl show`.
    assert mod._dav_auth() == ("ocrflow-worker", "from-credential")


def test_the_file_variable_is_still_honoured(monkeypatch, tmp_path: Path) -> None:
    secret = tmp_path / "pw"
    secret.write_text("from-file\n", encoding="utf-8")
    mod = _reload(
        monkeypatch,
        NC_OCR_DAV_USER="u",
        NC_OCR_NC_PASSWORD_FILE=str(secret),
    )
    assert mod._dav_auth() == ("u", "from-file")


def test_a_missing_password_names_the_places_to_look(monkeypatch) -> None:
    mod = _reload(monkeypatch, NC_OCR_DAV_USER="u")
    with pytest.raises(mod.DavCredentialsMissing) as exc:
        mod._dav_auth()
    message = str(exc.value)
    assert "CREDENTIALS_DIRECTORY" in message
    assert "NC_OCR_NC_PASSWORD_FILE" in message


def test_startup_check_refuses_to_run_without_credentials(monkeypatch) -> None:
    mod = _reload(monkeypatch, NC_OCR_DAV_USER="u")
    with pytest.raises(mod.DavCredentialsMissing):
        mod.verify_dav_credentials()
