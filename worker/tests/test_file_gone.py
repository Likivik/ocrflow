"""A file that vanished is not a malformed request.

Rows 8112 and 8113 on production read ``error_worker_request_malformed`` —
"the app refused the worker request as malformed (4xx)" — for a file the
owner had deleted after it was queued. Nothing was malformed and nothing
needed fixing: the work was moot.

``FileGone`` is raised by the client when the app answers 404 for the bytes.
It is a ``QueueProtocolError``, and the text rule caught its "no longer
exists" wording on the way to ``app_4xx``. It gets its own word now, and its
own code: ``error_worker_file_gone``, verdict ``drop``.
"""

from __future__ import annotations

from nc_ocr_flow.queue_client import FileGone, QueueProtocolError
from nc_ocr_flow.runner import classify_failure
from nc_ocr_flow.stage_events import reason_code_for


def test_a_missing_file_is_its_own_word() -> None:
    exc = FileGone("job 8113: the app reports the file no longer exists")

    assert classify_failure(exc) == "file_gone"


def test_a_missing_file_gets_its_own_code() -> None:
    assert reason_code_for("file_gone") == "error_worker_file_gone"


def test_a_vanished_job_is_still_a_malformed_request() -> None:
    """The old rule stays for the one case it was written for.

    A job the app does not know about is a protocol disagreement, not a file
    that existed and then did not.
    """
    exc = QueueProtocolError("no such job: 42")

    assert classify_failure(exc) == "app_4xx"


def test_a_file_gone_is_a_subclass_of_queue_protocol_error() -> None:
    """Why the text rule had to be narrowed, not removed."""
    assert issubclass(FileGone, QueueProtocolError)


def test_a_missing_model_is_not_an_unknown_code() -> None:
    """`ClassifierUnavailable` used to land on `unknown`.

    The image path wraps it in a RuntimeError with the words "classifier
    unavailable", so the class check alone is not enough: the text rule has to
    catch it, and the code has to say what the operator must do (install the
    model), not that the worker invented a word.
    """

    class ClassifierUnavailable(RuntimeError):
        pass

    wrapped = RuntimeError(
        "image classifier unavailable, refusing to convert '/x.jpg': model file not found"
    )

    assert classify_failure(wrapped) == "classifier_unavailable"
    assert classify_failure(ClassifierUnavailable("gone")) == "classifier_unavailable"
    assert reason_code_for("classifier_unavailable") == "error_classifier_unavailable"


def test_image_conversion_survives_the_bomb_guard(tmp_path) -> None:
    """The conversion path used to die where the classifier had already coped.

    `img2pdf` reads the header through Pillow, so a 247 M px JPEG raised
    DecompressionBombError *after* classify() had handled the same file — a raw
    exception the app had no code for. The guard is lifted for the embed only
    (img2pdf copies the JPEG stream, it does not decode pixels) and restored.
    """
    pytest = __import__("pytest")
    Image = pytest.importorskip("PIL.Image")
    from nc_ocr_flow.webhook_server import _image_to_pdf

    source = tmp_path / "scan.jpg"
    Image.new("RGB", (64, 64), "white").save(source, "JPEG")

    before = Image.MAX_IMAGE_PIXELS
    produced = _image_to_pdf(source)

    assert produced.exists() and produced.stat().st_size > 0
    assert before == Image.MAX_IMAGE_PIXELS, "the guard must be restored"

    # And a file the decoder refuses is reported as too large, not as a crash.
    from nc_ocr_flow.classifier import ImageTooLarge

    class _Boom:
        class Rotation:
            ifvalid = 1

        @staticmethod
        def convert(*_args, **_kwargs):
            raise Image.DecompressionBombError("Image size (247698504 pixels) exceeds limit")

    import sys

    original = sys.modules.get("img2pdf")
    sys.modules["img2pdf"] = _Boom
    try:
        with pytest.raises(ImageTooLarge):
            _image_to_pdf(source)
    finally:
        if original is None:
            sys.modules.pop("img2pdf", None)
        else:
            sys.modules["img2pdf"] = original
    assert before == Image.MAX_IMAGE_PIXELS, "the guard is restored even on refusal"


def test_an_empty_source_is_not_a_malformed_request() -> None:
    """The 422 body used to be read as file bytes.

    The app answers 422 with `{"ocs":{"data":{"reason_code":
    "error_source_file_zero_bytes"}}}`. `_download` returned that response, the
    caller streamed the JSON as if it were the document, saw an empty stream and
    the row said `error_worker_got_empty_file` — a transport story for a file
    that is simply 0 bytes and will never be anything else.
    """
    from nc_ocr_flow.queue_client import QueueProtocolError
    from nc_ocr_flow.runner import classify_failure
    from nc_ocr_flow.stage_events import reason_code_for

    exc = QueueProtocolError(
        "job 7: the app refused the source (error_source_file_zero_bytes)",
        status_code=422,
        app_reason_code="error_source_file_zero_bytes",
    )

    word = classify_failure(exc)
    assert word == "empty_source"
    assert reason_code_for(word) == "error_source_file_zero_bytes"


def test_a_422_without_a_named_reason_is_still_a_malformed_request() -> None:
    from nc_ocr_flow.queue_client import QueueProtocolError
    from nc_ocr_flow.runner import classify_failure

    exc = QueueProtocolError("job 7: the app answered 422", status_code=422)

    assert classify_failure(exc) == "app_4xx"


def test_the_download_endpoint_reads_the_reason_from_a_422(tmp_path) -> None:
    """Pinned at the client: the body is a reason, never the document."""
    import pytest

    from nc_ocr_flow.queue_client import QueueClient, QueueProtocolError

    class _Response:
        status_code = 422

        @staticmethod
        def json():
            return {"ocs": {"data": {"reason_code": "error_source_file_zero_bytes"}}}

    class _Session:
        @staticmethod
        def get(*_args, **_kwargs):
            return _Response()

    client = object.__new__(QueueClient)
    client.session = _Session()
    client.base_url = "https://example.invalid"
    client.timeout = 5
    client.secret = "s"
    client.worker_id = 1
    client.worker_name = "test:1:abcdef"
    client.host = "test"

    with pytest.raises(QueueProtocolError) as caught:
        client.fetch_file(9, tmp_path / "job.bin", claim="t")

    assert caught.value.status_code == 422
    assert caught.value.app_reason_code == "error_source_file_zero_bytes"


def test_both_spellings_of_tiff_are_readable() -> None:
    """Two 26 MB certificates were skipped as "unsupported" over four letters.

    Scanners write `.tif`; the list had only `.tiff`. The format is the same, so
    refusing one spelling is a bug in the list, not a decision about the file.
    """
    from nc_ocr_flow.webhook_server import IMAGE_EXTS, PROCESSABLE_EXTS

    assert ".tif" in IMAGE_EXTS
    assert ".tiff" in IMAGE_EXTS
    assert ".tif" in PROCESSABLE_EXTS
