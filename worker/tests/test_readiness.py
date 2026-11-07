"""Readiness is a capability check: it names what is missing, it does not guess."""

from __future__ import annotations

import logging

from nc_ocr_flow import readiness


def test_tesseract_without_binaries_is_not_ready(monkeypatch, tmp_path):
    monkeypatch.setattr(readiness.shutil, "which", lambda name: None)
    verdict = readiness.readiness_for("tesseract")
    assert verdict.ready is False
    assert "ocrmypdf binary" in verdict.missing
    assert "tesseract binary" in verdict.missing


def test_tesseract_ready_when_binaries_and_tessdata_exist(monkeypatch, tmp_path):
    monkeypatch.setattr(readiness.shutil, "which", lambda name: "/usr/bin/" + name)
    tessdata = tmp_path / "tessdata"
    tessdata.mkdir()
    (tessdata / "rus.traineddata").write_bytes(b"x")
    monkeypatch.setenv("TESSDATA_PREFIX", str(tessdata))
    verdict = readiness.readiness_for("tesseract")
    assert verdict.ready is True, verdict.missing


def test_google_names_every_missing_piece(monkeypatch):
    monkeypatch.setenv("NC_OCR_GOOGLE_KEY", "")
    monkeypatch.setenv("NC_OCR_GOOGLE_BUCKET", "")
    monkeypatch.setattr(readiness, "_credential_file_present", lambda: False)
    verdict = readiness.readiness_for("google")
    assert verdict.ready is False
    assert any("service-account key" in item for item in verdict.missing)
    assert any("NC_OCR_GOOGLE_BUCKET" in item for item in verdict.missing)


def test_unknown_engine_is_not_ready():
    verdict = readiness.readiness_for("magic-ocr")
    assert verdict.ready is False
    assert "unknown engine" in verdict.missing[0]


def test_startup_report_logs_but_never_raises(monkeypatch, caplog):
    monkeypatch.setenv("NC_OCR_ENGINE", "tesseract")
    monkeypatch.setattr(readiness.shutil, "which", lambda name: None)
    with caplog.at_level(logging.ERROR):
        verdict = readiness.report_configured_engine()
    assert verdict.ready is False
    assert any("jobs sent to this engine will fail" in r.message for r in caplog.records)
