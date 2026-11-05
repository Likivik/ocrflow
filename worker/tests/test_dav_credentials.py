"""WebDAV credentials: one source order, and no invented default.

The defect these cover was live in production: an unset `NC_OCR_NC_USER` meant the
worker ran as `"likivik"` — a person, with a person's authority over the whole
instance — so a typo in a unit file silently widened the blast radius instead of
stopping the worker.

These are pure tests of `resolve_dav_credentials`: the resolution order is a
function of its input, so the tests pass dicts instead of the environment. The
old shape — `importlib.reload` with a doctored environment, teardown reload to
undo it — died the moment another test file touched `sys.modules`, and its
teardown errored on every run after that.
"""

from __future__ import annotations

import pytest

from nc_ocr_flow import webhook_server as ws


def test_dav_user_wins_over_the_legacy_name() -> None:
    assert (
        ws.resolve_dav_credentials(
            {"NC_OCR_DAV_USER": "ocrflow-worker", "NC_OCR_NC_USER": "legacy-user"}
        )[0]
        == "ocrflow-worker"
    )


def test_the_legacy_name_still_works() -> None:
    # Nothing breaks for the deployment that is running today.
    assert ws.resolve_dav_credentials({"NC_OCR_NC_USER": "likivik"})[0] == "likivik"


def test_no_user_and_no_default() -> None:
    # The old behaviour: "likivik". The new one: nothing, so a missing variable
    # is a configuration error rather than a policy.
    assert ws.resolve_dav_credentials({"NC_OCR_NC_PASSWORD": "pw"}) == ("", "pw")


def test_the_credentials_directory_is_preferred(tmp_path) -> None:
    (tmp_path / "ocrflow-dav-password").write_text("from-credential\n", encoding="utf-8")
    # LoadCredential is the only source that never appears in `systemctl show`.
    assert ws.resolve_dav_credentials(
        {
            "NC_OCR_DAV_USER": "ocrflow-worker",
            "NC_OCR_NC_PASSWORD": "from-env",
            "CREDENTIALS_DIRECTORY": str(tmp_path),
        }
    ) == ("ocrflow-worker", "from-credential")


def test_the_file_variable_is_still_honoured(tmp_path) -> None:
    secret = tmp_path / "pw"
    secret.write_text("from-file\n", encoding="utf-8")
    assert ws.resolve_dav_credentials(
        {"NC_OCR_DAV_USER": "u", "NC_OCR_NC_PASSWORD_FILE": str(secret)}
    ) == ("u", "from-file")


def test_a_missing_password_names_the_places_to_look(monkeypatch) -> None:
    monkeypatch.setattr(ws, "NC_USER", "u")
    monkeypatch.setattr(ws, "NC_PASSWORD", "")
    with pytest.raises(ws.DavCredentialsMissing) as exc:
        ws._dav_auth()
    message = str(exc.value)
    assert "CREDENTIALS_DIRECTORY" in message
    assert "NC_OCR_NC_PASSWORD_FILE" in message


def test_no_user_is_a_configuration_error(monkeypatch) -> None:
    # `_dav_auth` refuses an empty user, and the message names the variable
    # that fixes it — not a default identity.
    monkeypatch.setattr(ws, "NC_USER", "")
    monkeypatch.setattr(ws, "NC_PASSWORD", "pw")
    with pytest.raises(ws.DavCredentialsMissing, match="NC_OCR_DAV_USER"):
        ws._dav_auth()


def test_startup_check_refuses_to_run_without_credentials(monkeypatch) -> None:
    monkeypatch.setattr(ws, "NC_USER", "")
    monkeypatch.setattr(ws, "NC_PASSWORD", "")
    with pytest.raises(ws.DavCredentialsMissing):
        ws.verify_dav_credentials()
