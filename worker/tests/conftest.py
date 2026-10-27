"""Pytest config: slow marker, fixtures."""

import os

import pytest

# `webhook_server` no longer invents a WebDAV user: production sets one
# explicitly (`NC_OCR_NC_USER` in the NixOS aspect) and an unset value is now a
# start-up error. The suite has always exercised DAV paths as a fixed user, so it
# declares that user here instead of relying on a default that was really a
# person's account. Set before any test module imports the server.
os.environ.setdefault("NC_OCR_DAV_USER", "likivik")
os.environ.setdefault("NC_OCR_NC_PASSWORD", "test-pass")


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
