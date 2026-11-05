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

# The engine is instance policy and has **no default** in production: an
# instance that has not chosen refuses to run rather than uploading page images
# to a provider nobody picked. The suite is about engine-agnostic plumbing, so it
# declares the offline engine once here — the absence of one is a test of its own
# (test_engine_required.py), not an accident of the environment.
os.environ.setdefault("NC_OCR_ENGINE", "tesseract")

# No background threads at import, suite-wide. The server used to start its worker
# loops when the module was imported, so they claimed jobs across test boundaries
# (~1 failing run in 5, a different test each time) and every module that imports
# the server inherited a running scheduler it never asked for. Production is
# unchanged: `start_scheduler()` still runs for real at the bottom of the module
# when this variable is unset. A test that needs live workers asks for `workers`.
os.environ.setdefault("NC_OCR_RUN_SCHEDULER", "0")


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
    # repin, not env-flip: db_path() resolves once, so only an explicit move
    # keeps the cached path and the env in agreement. This fixture is the one
    # place in the suite allowed to move the store.
    jobstore.repin(directory / "jobs.db")
    assert jobstore._ready(), f"jobstore did not initialise at {jobstore.db_path()}"
    yield directory
    if previous is None:
        os.environ.pop("NC_OCR_STATE_DIR", None)
    else:
        os.environ["NC_OCR_STATE_DIR"] = previous
    jobstore._reset_for_tests()
    if str(directory).startswith("/dev/shm"):
        shutil.rmtree(directory, ignore_errors=True)


@pytest.fixture(autouse=True)
def _no_stray_workers():
    """Nothing is running between tests; the `workers` fixture starts what a test needs."""
    from nc_ocr_flow import webhook_server as ws

    ws.stop_scheduler()
    yield
    ws.stop_scheduler()
