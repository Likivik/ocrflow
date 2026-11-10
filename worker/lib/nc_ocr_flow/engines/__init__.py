"""Engine registry for nc-ocr-flow.

Three engines, one process_pdf signature each:

  google    — Google Cloud Vision DOCUMENT_TEXT_DETECTION on whole PDF
              (default; best for Russian/English mixed-content scans)
  tesseract — tesseract-only whole-doc pass (cheap; born-digital-friendly)
  minimax   — MiniMax M3 vision via tool-use, per-page (escalation tier)

Engine selection:

  - Explicit ``process_pdf(pdf, engine='google')``
  - Otherwise ``NC_OCR_ENGINE``; **there is no default engine** — an
              instance that has not chosen raises ``EngineNotConfigured``
  - Anything else → ``ValueError("invalid engine: ...")``

Each engine module exposes:

  name : str                       # canonical key in the registry
  process_pdf(pdf_path, output_pdf=None) -> ocr.ProcessResult
                                     # mirrors the orchestrator signature

The orchestrator (ocr.process_pdf) is the only place that calls into
an engine; webhook_server / watcher just call process_pdf and let it
resolve the engine.
"""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

from nc_ocr_flow.config import env_str

# --- Engine name constants -------------------------------------------------

GOOGLE = "google"
TESSERACT = "tesseract"
MINIMAX = "minimax"

VALID_ENGINES: tuple[str, ...] = (GOOGLE, TESSERACT, MINIMAX)

# Deliberately empty: the engine decides whether page images leave the server,
# so an instance must choose one (``occ ocrflow:engine tesseract``). It used to
# default to ``google``, which meant a fresh install silently uploaded scans.
NONE = "none"
DEFAULT_ENGINE = ""


class EngineNotConfigured(RuntimeError):
    """No engine has been chosen for this instance."""


def resolve_engine(name: str | None = None) -> str:
    """Validate and return an engine name.

    Lookup order:
      1. Explicit ``name`` arg if not None
      2. ``NC_OCR_ENGINE`` env var
      3. ``DEFAULT_ENGINE`` (= "google")

    Raises ``ValueError`` on an unknown engine name.
    """
    raw = name if name is not None else env_str("NC_OCR_ENGINE", "")
    chosen = (raw or DEFAULT_ENGINE).strip().lower()
    if chosen in ("", NONE):
        raise EngineNotConfigured(
            "no OCR engine configured: set NC_OCR_ENGINE to one of "
            f"{', '.join(VALID_ENGINES)} (or run `occ ocrflow:engine <name>`)"
        )
    if chosen not in VALID_ENGINES:
        raise ValueError(f"invalid engine: {raw!r} (expected one of {VALID_ENGINES})")
    return chosen


def get_engine_module(name: str) -> object:
    """Import and return the engine module for *name*.

    Lazy-imports each engine module so unused engines (and their
    heavyweight deps) don't load at startup.
    """
    if name == GOOGLE:
        from . import google_engine

        return google_engine
    if name == TESSERACT:
        from . import tesseract_engine

        return tesseract_engine
    if name == MINIMAX:
        from . import minimax_engine

        return minimax_engine
    raise ValueError(f"invalid engine: {name!r}")


def get_process_pdf(name: str) -> Callable[..., Any]:
    """Return the ``process_pdf`` callable for engine *name*."""
    mod = get_engine_module(name)
    fn = getattr(mod, "process_pdf", None)
    if fn is None:
        raise RuntimeError(f"engine {name!r} has no process_pdf()")
    return fn


__all__ = [
    "DEFAULT_ENGINE",
    "GOOGLE",
    "MINIMAX",
    "TESSERACT",
    "VALID_ENGINES",
    "get_engine_module",
    "get_process_pdf",
    "resolve_engine",
]
