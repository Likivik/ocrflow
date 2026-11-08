"""The classifier must never turn a terminal answer into retryable work."""

import pytest
from nc_ocr_flow.queue_client import FileGone, QueueProtocolError
from nc_ocr_flow.runner import classify_failure


@pytest.mark.parametrize("status,expected", [
    (422, "app_4xx"),        # empty source: will never work
    (404, "app_4xx"),        # file gone
    (401, "credentials_missing"),
    (403, "credentials_missing"),
    (400, "app_4xx"),
    (429, "app_4xx"),
    (500, "app_5xx"),
    (503, "app_5xx"),
])
def test_status_outranks_text(status, expected):
    exc = QueueProtocolError(f"unexpected {status} fetching job 7", status_code=status)
    assert classify_failure(exc) == expected


def test_422_is_never_retryable():
    assert classify_failure(QueueProtocolError("unexpected 422", status_code=422)) != "app_5xx"


def test_no_status_stays_bounded_retryable():
    assert classify_failure(QueueProtocolError("claim returned a job we cannot read")) == "app_5xx"


def test_empty_stream_text_still_wins_without_status():
    exc = QueueProtocolError("job 7: the app streamed an empty file")
    assert classify_failure(exc) == "empty_stream"


def test_file_gone_is_not_a_transport_retry():
    assert classify_failure(FileGone("job 7: no longer exists")) in ("app_4xx", "unknown")
