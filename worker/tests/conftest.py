"""Pytest config: slow marker, fixtures."""

import os
import shutil
import tempfile
from pathlib import Path

import pytest

# `webhook_server` no longer invents a WebDAV user: production sets one
# explicitly (`NC_OCR_NC_USER` in the NixOS aspect) and an unset value is now a
# start-up error. The suite has always exercised DAV paths as a fixed user, so it
# declares that user here instead of relying on a default that was really a
# person's account. Set before any test module imports the server.
os.environ.setdefault("NC_OCR_DAV_USER", "likivik")
os.environ.setdefault("NC_OCR_NC_PASSWORD", "test-pass")

# Every test module gets one writable index, and a failed statement raises
# instead of degrading to an empty answer. Without this, a broken SQLite file
# surfaced as `assert 300 == 600` in an unrelated settings test — the store was
# gone, the assertion blamed the value.
os.environ.setdefault("NC_OCR_STATE_STRICT", "1")


def pytest_addoption(parser):
    parser.addoption(
        "--runslow",
        action="store_true",
        default=False,
        help="run slow tests (ocrmypdf + tesseract, ~30s each)",
    )


def pytest_configure(config):
    config.addinivalue_line("markers", "slow: marks tests as slow")


def pytest_collection_modifyitems(config, items):
    if not config.getoption("--runslow"):
        skip_slow = pytest.mark.skip(reason="need --runslow to run")
        for item in items:
            if "slow" in item.keywords:
                item.add_marker(skip_slow)


@pytest.fixture(scope="module", autouse=True)
def _own_state_dir(tmp_path_factory):
    """One index per test module, healthy, or the module fails immediately.

    A single shared file is wrong twice over: `test_jobstore` deliberately points
    the store at paths that cannot be a database (that is its subject), which left
    the *shared* file broken for every later module — `locking protocol`, `file is
    not a database`, and two unrelated tests failing. Per module keeps the damage
    inside the module that caused it, and the `_ready()` assertion means a module
    never starts against a dead index.
    """
    from nc_ocr_flow import jobstore

    # WAL on the runner's workspace filesystem is what produced
    # `sqlite3.OperationalError: disk I/O error` — three CI runs, three different
    # symptoms, one cause. /dev/shm is a real tmpfs there and WAL behaves.
    shm = Path("/dev/shm")
    if shm.is_dir() and os.access(shm, os.W_OK):
        directory = Path(tempfile.mkdtemp(prefix="ncocr-state-", dir=shm))
    else:
        directory = tmp_path_factory.mktemp("state")
    previous = os.environ.get("NC_OCR_STATE_DIR")
    os.environ["NC_OCR_STATE_DIR"] = str(directory)
    jobstore._reset_for_tests()
    jobstore.init()
    assert jobstore._ready(), f"jobstore did not initialise at {jobstore.db_path()}"
    yield directory
    if previous is None:
        os.environ.pop("NC_OCR_STATE_DIR", None)
    else:
        os.environ["NC_OCR_STATE_DIR"] = previous
    jobstore._reset_for_tests()
    if str(directory).startswith("/dev/shm"):
        shutil.rmtree(directory, ignore_errors=True)
