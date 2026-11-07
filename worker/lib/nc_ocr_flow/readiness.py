"""Engine readiness: name the missing dependency before any job runs.

The failure this prevents is cheap and was real: the module accepts
``NC_OCR_ENGINE=tesseract`` on a host that has ``tessdata`` installed but no
``ocrmypdf`` and no ``tesseract`` binary. Nothing complains at boot; the first
job that reaches that engine dies, after the file has been fetched, classified
and (for other engines) billed.

Readiness is a **capability check**, not a promise: each engine declares the
binaries, models and credentials it needs, and the check reports exactly which
of them are absent. The check never raises by itself — callers decide whether an
unready engine is fatal (a worker started with that engine) or informational
(the admin surface listing what this host can do).
"""

from __future__ import annotations

import logging
import os
import shutil
from dataclasses import dataclass
from pathlib import Path

log = logging.getLogger(__name__)

# Where systemd puts RuntimeDirectory/credentials for the worker unit.
_CREDENTIALS_ENV = "CREDENTIALS_DIRECTORY"
_GOOGLE_KEY_ENV = "NC_OCR_GOOGLE_KEY"
_GOOGLE_BUCKET_ENV = "NC_OCR_GOOGLE_BUCKET"
_MINIMAX_KEY_ENV = "MINIMAX_API_KEY"
_ENGINE_ENV = "NC_OCR_ENGINE"


@dataclass(frozen=True)
class Readiness:
    """What this host can and cannot do for one engine."""

    engine: str
    ready: bool
    missing: tuple[str, ...]

    def message(self) -> str:
        if self.ready:
            return f"engine {self.engine!r} is ready"
        return f"engine {self.engine!r} is not ready: missing " + ", ".join(self.missing)


def _credential_file_present() -> bool:
    """True when the Google key exists in any of the places the module reads."""
    explicit = os.environ.get(_GOOGLE_KEY_ENV, "").strip()
    if explicit and Path(explicit).is_file():
        return True
    directory = os.environ.get(_CREDENTIALS_ENV, "").strip()
    return bool(directory and (Path(directory) / "google-ocr-key").is_file())


def _tessdata_present() -> bool:
    for candidate in (
        os.environ.get("TESSDATA_PREFIX", ""),
        "/usr/share/tesseract-ocr/5/tessdata",
        "/usr/share/tessdata",
    ):
        if candidate and (Path(candidate) / "rus.traineddata").is_file():
            return True
    return False


def readiness_for(engine: str) -> Readiness:
    """Check one engine against this host. Pure inspection; no network."""
    missing: list[str] = []

    if engine == "tesseract":
        if shutil.which("ocrmypdf") is None:
            missing.append("ocrmypdf binary")
        if shutil.which("tesseract") is None:
            missing.append("tesseract binary")
        if not _tessdata_present():
            missing.append("tessdata/rus.traineddata")
    elif engine == "google":
        try:  # import probe: the ImportError is the signal
            import google.cloud.vision  # noqa: F401
        except Exception:
            missing.append("google-cloud-vision package")
        if not _credential_file_present():
            missing.append(
                "service-account key (NC_OCR_GOOGLE_KEY or $CREDENTIALS_DIRECTORY/google-ocr-key)"
            )
        if not os.environ.get(_GOOGLE_BUCKET_ENV, "").strip():
            missing.append(f"{_GOOGLE_BUCKET_ENV} (staging bucket)")
    elif engine == "minimax":
        if not os.environ.get(_MINIMAX_KEY_ENV, "").strip():
            missing.append(f"{_MINIMAX_KEY_ENV}")
    else:
        missing.append(f"unknown engine {engine!r}")

    return Readiness(engine=engine, ready=not missing, missing=tuple(missing))


def configured_engine() -> str:
    return (os.environ.get(_ENGINE_ENV) or "google").strip().lower()


def report_configured_engine() -> Readiness:
    """Log the startup readiness verdict for the configured engine.

    Deliberately a loud log and **not** an exit: a readiness check that is
    wrong in the strict direction stops a healthy worker, which is a worse
    outage than a late job failure. Once this message has been observed in a
    real deploy, promoting it to a refusal is a one-line change.
    """
    verdict = readiness_for(configured_engine())
    if verdict.ready:
        log.info("readiness: %s", verdict.message())
    else:
        log.error("readiness: %s — jobs sent to this engine will fail", verdict.message())
    return verdict
