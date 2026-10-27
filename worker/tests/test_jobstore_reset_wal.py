"""The WAL belongs to the deleted file — deleting one must delete the other.

`_reset_for_tests()` unlinked `jobs.db` and left `jobs.db-wal` / `jobs.db-shm`
behind. The store runs in WAL mode, so the next connection replayed a journal
whose database was gone and SQLite answered `database disk image is malformed`
or `file is not a database` — in whatever test happened to open the store next,
usually one that had nothing to do with it. Five CI failures today traced here.
"""

from __future__ import annotations

from pathlib import Path

from nc_ocr_flow import jobstore


def test_reset_removes_the_journal_files_too(tmp_path: Path, monkeypatch) -> None:
    monkeypatch.setenv("NC_OCR_STATE_DIR", str(tmp_path))
    monkeypatch.setattr(jobstore, "_DB_PATH", None)
    monkeypatch.setattr(jobstore, "_INITIALISED", False)
    jobstore.init()
    assert jobstore._ready()

    db = jobstore.db_path()
    # WAL mode creates these lazily; write a row so they exist.
    jobstore.setting_put("k", "v")
    sidecars = [db.with_name(db.name + s) for s in ("-wal", "-shm")]
    existing = [p for p in sidecars if p.exists()]
    assert existing, "expected the WAL sidecars to exist after a write"

    jobstore._reset_for_tests()

    assert not db.exists(), "the database file must be gone"
    for p in sidecars:
        assert not p.exists(), f"{p.name} survived the reset — the next open replays a stale WAL"

    # And a fresh store opens cleanly rather than reporting a malformed image.
    jobstore.init()
    assert jobstore._ready()
    assert jobstore.settings_all().get("k") in (None, "")
