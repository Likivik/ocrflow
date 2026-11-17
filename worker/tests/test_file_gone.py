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
