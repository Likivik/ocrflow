"""A broken index must say so, not answer "300".

CI spent a rerun on `assert 300 == 600` inside a settings test while the real
event in the log was `jobstore: init failed (disk I/O error)`. The store was
gone; the assertion blamed a value. Strict mode makes that class of failure name
itself.
"""

from __future__ import annotations

import sqlite3
from pathlib import Path

import pytest

from nc_ocr_flow import jobstore


@pytest.fixture()
def broken_store(monkeypatch, tmp_path: Path):
    """Point the store at a path that cannot be a database."""
    # A directory where the file should be: sqlite cannot open it.
    (tmp_path / "jobs.db").mkdir()
    monkeypatch.setenv("NC_OCR_STATE_DIR", str(tmp_path))
    monkeypatch.setattr(jobstore, "_DB_PATH", None)
    monkeypatch.setattr(jobstore, "_INITIALISED", False)
    yield tmp_path
    monkeypatch.undo()
    jobstore._reset_for_tests()


def test_strict_mode_raises_instead_of_degrading(broken_store, monkeypatch) -> None:
    monkeypatch.setenv("NC_OCR_STATE_STRICT", "1")
    with pytest.raises(jobstore.JobStoreUnavailable, match="init"):
        jobstore.init()
    # `_ready()` retries the init, so in strict mode it raises rather than
    # reporting False — a caller cannot mistake "broken" for "empty".
    with pytest.raises(jobstore.JobStoreUnavailable):
        jobstore._ready()


def test_permissive_mode_still_degrades_for_a_live_worker(broken_store, monkeypatch) -> None:
    # A worker that cannot write history should still process documents.
    monkeypatch.setenv("NC_OCR_STATE_STRICT", "0")
    jobstore.init()  # no raise
    assert jobstore._ready() is False


def test_a_write_failure_is_loud_in_strict_mode(monkeypatch, tmp_path: Path) -> None:
    monkeypatch.setenv("NC_OCR_STATE_STRICT", "1")
    monkeypatch.setenv("NC_OCR_STATE_DIR", str(tmp_path))
    monkeypatch.setattr(jobstore, "_DB_PATH", None)
    monkeypatch.setattr(jobstore, "_INITIALISED", True)  # pretend init worked
    # Drop the table behind its back: the next statement fails at the SQL layer.
    with jobstore._connect() as conn:
        conn.execute("DROP TABLE IF EXISTS jobs")
    with pytest.raises(jobstore.JobStoreUnavailable, match="claim_next"):
        jobstore.claim_next()


def test_a_lock_is_contention_not_corruption(monkeypatch) -> None:
    """Two connections can collide on a WAL file; that must not fail a document.

    CI proved it: a catch-up thread and a test both opened the index and the
    strict raise turned `database is locked` into a failed scan.
    """
    monkeypatch.setenv("NC_OCR_STATE_STRICT", "1")
    jobstore._fail("init", sqlite3.OperationalError("database is locked"))
    jobstore._fail("claim_next", sqlite3.OperationalError("database is busy"))


def test_corruption_still_raises(monkeypatch) -> None:
    monkeypatch.setenv("NC_OCR_STATE_STRICT", "1")
    with pytest.raises(jobstore.JobStoreUnavailable):
        jobstore._fail("file_put", sqlite3.OperationalError("no such table: files"))
    with pytest.raises(jobstore.JobStoreUnavailable):
        jobstore._fail("query", sqlite3.DatabaseError("file is not a database"))
