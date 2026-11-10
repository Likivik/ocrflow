"""Env parsing: one strict contract, and a typo must never pick a branch.

Worker env is produced by Nix (`toString true` -> "1", `toString false` -> ""), so
there is no tolerant producer to accommodate. `NC_OCR_KEEP_SOURCE_IMAGE=off` used
to keep the user's source image because a falsy-list parser forgot `off`; it now
raises at import.
"""

import pytest

from nc_ocr_flow.config import ConfigError, env_flag, env_float, env_int, env_str


def test_flag_accepts_one(monkeypatch):
    monkeypatch.setenv("NC_TEST_FLAG", "1")
    assert env_flag("NC_TEST_FLAG", False) is True


def test_flag_accepts_zero(monkeypatch):
    monkeypatch.setenv("NC_TEST_FLAG", "0")
    assert env_flag("NC_TEST_FLAG", True) is False


def test_flag_empty_is_false(monkeypatch):
    monkeypatch.setenv("NC_TEST_FLAG", "")
    assert env_flag("NC_TEST_FLAG", True) is False


def test_flag_unset_returns_default(monkeypatch):
    monkeypatch.delenv("NC_TEST_FLAG", raising=False)
    assert env_flag("NC_TEST_FLAG", True) is True
    assert env_flag("NC_TEST_FLAG", False) is False


@pytest.mark.parametrize("value", ["off", "no", "false", "true", "yes", "on", "ture"])
def test_flag_rejects_anything_else(monkeypatch, value):
    """Deliberate divergence from Nextcloud's tolerant reader: env has no human
    producer, so 'off'/'yes'/typos are configuration errors, not synonyms."""
    monkeypatch.setenv("NC_TEST_FLAG", value)
    with pytest.raises(ConfigError) as excinfo:
        env_flag("NC_TEST_FLAG", False)
    assert "NC_TEST_FLAG" in str(excinfo.value)


def test_int_parses_and_defaults(monkeypatch):
    monkeypatch.setenv("NC_TEST_INT", "42")
    assert env_int("NC_TEST_INT", 7) == 42
    monkeypatch.delenv("NC_TEST_INT", raising=False)
    assert env_int("NC_TEST_INT", 7) == 7


@pytest.mark.parametrize("value", ["4.5", "abc", "42s"])
def test_int_rejects_garbage(monkeypatch, value):
    monkeypatch.setenv("NC_TEST_INT", value)
    with pytest.raises(ConfigError):
        env_int("NC_TEST_INT", 7)


def test_float_parses_and_defaults(monkeypatch):
    monkeypatch.setenv("NC_TEST_FLOAT", "1.5")
    assert env_float("NC_TEST_FLOAT", 0.5) == 1.5
    monkeypatch.delenv("NC_TEST_FLOAT", raising=False)
    assert env_float("NC_TEST_FLOAT", 0.5) == 0.5


def test_str_is_preserved_verbatim_and_defaults(monkeypatch):
    """Deliberately not stripped: the operator's value is not rewritten. An empty
    value is a value, so only an *unset* variable yields the default."""
    monkeypatch.setenv("NC_TEST_STR", "  value  ")
    assert env_str("NC_TEST_STR", "d") == "  value  "
    monkeypatch.delenv("NC_TEST_STR", raising=False)
    assert env_str("NC_TEST_STR", "d") == "d"


def test_keep_source_image_off_raises_at_import(monkeypatch):
    """The measured regression: 'off' used to KEEP the image."""
    monkeypatch.setenv("NC_OCR_KEEP_SOURCE_IMAGE", "off")
    import importlib

    import nc_ocr_flow.webhook_server as ws

    with pytest.raises(ConfigError):
        importlib.reload(ws)
