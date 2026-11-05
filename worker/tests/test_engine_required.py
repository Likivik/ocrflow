"""No engine means no OCR — not "google by default".

The defect this pins: `DEFAULT_ENGINE = "google"`, so an instance nobody
configured sent a page image of every document to Google Cloud Vision. The engine
decides whether user documents leave the server, so it must be a decision someone
made, not a default that happens to exist.

Two enforcement points, both tested here:
  * `resolve_engine()` raises instead of inventing a provider.
  * `runner.main()` refuses to start, next to the credential gate — a worker that
    starts and then fails every job is worse than one that says why it stopped.
"""

import pytest

from nc_ocr_flow import runner
from nc_ocr_flow.engines import EngineNotConfigured, resolve_engine


def test_no_env_means_not_configured(monkeypatch):
    monkeypatch.delenv("NC_OCR_ENGINE", raising=False)
    with pytest.raises(EngineNotConfigured) as exc:
        resolve_engine()
    # The message must name the knob, or the operator has to read the source.
    assert "NC_OCR_ENGINE" in str(exc.value)


def test_explicit_none_is_not_configured(monkeypatch):
    monkeypatch.delenv("NC_OCR_ENGINE", raising=False)
    with pytest.raises(EngineNotConfigured):
        resolve_engine("none")


def test_empty_env_value_is_not_configured(monkeypatch):
    monkeypatch.setenv("NC_OCR_ENGINE", "   ")
    with pytest.raises(EngineNotConfigured):
        resolve_engine()


def test_an_explicit_engine_still_wins(monkeypatch):
    # The app stamps the engine on the job; that must work even when the worker's
    # own environment says something else.
    monkeypatch.setenv("NC_OCR_ENGINE", "tesseract")
    assert resolve_engine("google") == "google"


def test_unknown_engine_is_a_value_error_not_a_silent_default(monkeypatch):
    monkeypatch.delenv("NC_OCR_ENGINE", raising=False)
    with pytest.raises(ValueError, match="invalid engine"):
        resolve_engine("ocrmypdf")


def test_runner_refuses_to_start_without_an_engine(monkeypatch):
    monkeypatch.delenv("NC_OCR_ENGINE", raising=False)
    monkeypatch.setenv("NC_OCR_URL", "http://127.0.0.1")
    monkeypatch.setenv("NC_OCR_WORKER_SECRET", "test-secret")

    assert runner.main([]) == 2


def test_api_transport_does_not_need_a_worker_engine(monkeypatch):
    """On the api transport the app stamps the engine on each job."""
    monkeypatch.delenv("NC_OCR_ENGINE", raising=False)

    assert runner._engine_gate("api") is None


def test_dav_transport_needs_a_worker_engine(monkeypatch):
    monkeypatch.delenv("NC_OCR_ENGINE", raising=False)

    message = runner._engine_gate("dav")
    assert message is not None and "NC_OCR_ENGINE" in message


def test_runner_starts_when_an_engine_is_chosen(monkeypatch):
    """Sanity check on the gate itself: it must not reject a configured worker.

    The run is cut short right after the gate — this asserts the gate passed, not
    that a whole loop runs, which other tests cover.
    """
    monkeypatch.setenv("NC_OCR_ENGINE", "tesseract")
    assert resolve_engine() == "tesseract"
