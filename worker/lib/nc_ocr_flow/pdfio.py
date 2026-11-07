"""The single PDF write primitive.

Every PDF write in the worker goes through :func:`write_in_place`. The rule is
simple and was expensive to learn: **one artefact, one writer**.

Why this module exists
----------------------
The text-layer embed (``ocr._finalize``) and the provenance stamp
(``webhook_server._stamp_metadata``) each carried a copy of the same
incremental-save-with-fallback algorithm, and the copies drifted: different
``garbage=`` values, different verification (one checked images, the other did
not), and two places to fix for every bug. PRs #54 and #57 were the same fix
applied twice, days apart. Integration tests passed after the first, in both the
CI and the deployed build, with the production failure unchanged; this is the
architecture that made that possible.

The double-write incident is documented in ``webhook_server`` ("every successful
job writes the target PDF twice"). This module is one write; the compose stage
in the plan is the next step.

The contract
------------
:func:`write_in_place`:

1. Try ``doc.saveIncr()`` — the right write for a user's live document, because
   it appends a revision instead of rebuilding the file.
2. If that is refused (PyMuPDF raises ``ValueError("save to original must be
   incremental")`` for documents it had to repair on open), write a sibling temp
   file, **verify the rebuilt artefact** against the document we still hold
   (page count, and image count when required), ``fsync`` the bytes, inherit the
   original's permission bits, and ``os.replace`` it atomically.
3. Never leave a temp file behind, on any failure path.

``saveIncr`` has one known caveat: it fails on files opened from a read-only
handle, which is the pre-existing behaviour of both call sites and is not this
module's problem to change.
"""

from __future__ import annotations

import logging
import os
import stat
from dataclasses import dataclass
from pathlib import Path
from typing import Any

log = logging.getLogger(__name__)

# Suffix of the sibling temp file. Kept out of the caller's naming so both call
# sites cannot invent their own (they did: ".stamp.tmp").
_TMP_SUFFIX = ".write.tmp"


@dataclass(frozen=True)
class WriteReport:
    """What the writer actually did — measured, not assumed."""

    mode: str  # "incremental" | "rewrite"
    pages: int
    images: int
    path: Path


def _count_images(doc: Any) -> int:
    # Indexing, not iteration: PyMuPDF's Document is not iterable and mypy
    # rejects ``for page in doc``.
    return sum(len(doc.load_page(i).get_images()) for i in range(doc.page_count))


def _fsync_file(path: Path) -> None:
    fd = os.open(str(path), os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _fsync_dir(directory: Path) -> None:
    flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
    fd = os.open(str(directory), flags)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _inherit_mode(tmp: Path, original: Path) -> None:
    """Give the temp file the original's permission bits before it replaces it.

    Without this, ``os.replace`` swaps in a file created under the process
    umask, and a 0600 user scan silently becomes 0644 on disk. The mode is read
    from the original when it exists; a brand-new file keeps the umask default.
    """
    try:
        mode = stat.S_IMODE(original.stat().st_mode)
    except FileNotFoundError:
        return
    os.chmod(tmp, mode)


def write_in_place(
    doc: Any,
    path: str | Path,
    *,
    garbage: int = 0,
    deflate: bool = True,
    require_images: bool = True,
) -> WriteReport:
    """Persist ``doc`` to ``path``: incremental first, verified rewrite second.

    Args:
        doc: An open PyMuPDF document. Not closed by this function.
        path: The file to write; must be the file ``doc`` was opened from for
            the incremental fast path to apply.
        garbage: PyMuPDF garbage-collection level for the rewrite path. The
            embed path historically used ``4``, the stamp path ``0``; callers
            pass their own value rather than the module picking one.
        deflate: Compress streams on the rewrite path.
        require_images: Fail the rewrite if the image count changed. The text
            embed and the stamp both preserve every page image, so the strict
            check is the default; loosening it is an explicit caller decision.

    Returns:
        :class:`WriteReport` with the mode actually taken and the counts
        measured on the persisted artefact.

    Raises:
        RuntimeError: the rewrite lost pages or (when required) images; the
            original is left untouched.
    """
    target = Path(path)
    pages_before = doc.page_count
    images_before = _count_images(doc) if require_images else 0

    try:
        doc.saveIncr()
        return WriteReport("incremental", pages_before, images_before, target)
    except Exception as exc:
        log.warning(
            "pdfio: incremental save refused for %s (%s: %s); rewriting",
            target,
            type(exc).__name__,
            exc,
        )

    tmp = target.with_name(target.name + _TMP_SUFFIX)
    try:
        doc.save(str(tmp), garbage=garbage, deflate=deflate)

        import pymupdf

        rebuilt = pymupdf.open(str(tmp))
        try:
            pages_after = rebuilt.page_count
            images_after = _count_images(rebuilt) if require_images else 0
        finally:
            rebuilt.close()

        if pages_after != pages_before or (require_images and images_after != images_before):
            raise RuntimeError(
                f"rewrite changed {target}: {pages_before} pages/"
                f"{images_before} images became {pages_after}/{images_after}; "
                "refusing to replace the original"
            )

        # Durability before visibility: the bytes must be on disk before the
        # rename makes them the file everyone else sees.
        _fsync_file(tmp)
        _inherit_mode(tmp, target)
        os.replace(tmp, target)
        _fsync_dir(target.parent)
        return WriteReport("rewrite", pages_after, images_after, target)
    finally:
        if tmp.exists():
            tmp.unlink()
