"""Backwards-compat shim — re-exports the MiniMax-M3 helpers that used
to live here.

The implementation has moved to ``nc_ocr_flow.engines.minimax_engine``;
this module re-exports the per-page entry point and the helpers that
were used by other modules (and the existing test suite) under their
original names so external imports keep working.
"""

from __future__ import annotations

from nc_ocr_flow.engines.minimax_engine import (
    ENGINE_NAME,
    LOW_CONF_FLOOR,
    MINIMAX_MODEL,
    MINIMAX_URL,
    OCR_PROMPT_TOKEN_COST,
    OCR_TOOLS,
    PROMPT,
    MiniMaxError,
    _blocks_from_toolcall,
    _chat,
    _html_table_to_text,
    ocr_page_minimax,
)

__all__ = [
    "ENGINE_NAME",
    "LOW_CONF_FLOOR",
    "MINIMAX_MODEL",
    "MINIMAX_URL",
    "OCR_PROMPT_TOKEN_COST",
    "OCR_TOOLS",
    "PROMPT",
    "MiniMaxError",
    "_blocks_from_toolcall",
    "_chat",
    "_html_table_to_text",
    "ocr_page_minimax",
]
