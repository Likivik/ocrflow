"""The engine an admin chose must survive the whole path to the worker.

The defect this pins: `queue/next` returned ``engine`` and the jobs table stored
it, but the worker's claim parser read only ``id/path/file_id/etag/size`` — so
the engine was dropped on the floor and the worker silently used its own
``NC_OCR_ENGINE`` env instead. On the api transport that env is unset, so every
job died with "no OCR engine configured" no matter what the app said.

Both ends are covered: the parser reads ``engine``, and ``run_once`` hands it to
the orchestrator. The second test is deliberately mutation-sensitive — delete the
``engine=job.engine`` argument in ``run_once`` and it must go red, or it is not
protecting anything.
"""

from nc_ocr_flow import queue_client, runner
from nc_ocr_flow.queue_client import Job, QueueProtocolError


def _payload(**overrides):
    data = {
        "id": 7,
        "path": "admin/files/doc.pdf",
        "file_id": 42,
        "etag": "abc",
        "size": 10,
        "attempts": 1,
        "engine": "tesseract",
    }
    data.update(overrides)
    return {"ocs": {"meta": {"statuscode": 200}, "data": data}}


def test_claim_reads_the_engine_the_app_stamped():
    job = Job.from_api(_payload()["ocs"]["data"])
    assert job.engine == "tesseract"


def test_a_claim_without_engine_is_still_accepted():
    # Older app builds do not send the field. That must parse, not raise: the
    # fallback is the worker's own env, and the fail-closed gate lives there.
    data = _payload()["ocs"]["data"]
    del data["engine"]
    job = Job.from_api(data)
    assert job.engine is None


def test_engine_is_optional_not_required():
    job = Job.from_api(_payload(engine=None)["ocs"]["data"])
    assert job.engine is None


def test_run_once_hands_the_claimed_engine_to_the_orchestrator():
    """Mutation check: remove ``engine=job.engine`` from run_once and this fails."""

    seen = {}

    def process(path, node_id, engine=None, job_id=None, claim_token=None):
        seen["engine"] = engine
        seen["job_id"] = job_id
        return {"status": "done"}

    calls = []

    class FakeClient:
        def claim(self):
            calls.append("claim")
            return Job(
                id=7,
                path="admin/files/doc.pdf",
                file_id=42,
                etag="abc",
                size=10,
                attempts=1,
                engine="google",
            )

        def result(self, job_id, **fields):
            return None

    handled = runner.run_once(FakeClient(), process)
    assert handled is True
    assert seen["engine"] == "google", (
        "run_once dropped the claimed engine: the admin's choice never reaches "
        "the orchestrator and the worker falls back to its own env"
    )
    assert seen["job_id"] == 7
    assert calls == ["claim"]


def test_a_job_the_app_cannot_describe_is_rejected_loudly():
    # Identity fields stay indexed on purpose; a claim the worker cannot read
    # must raise, not limp on with defaults.
    data = _payload()["ocs"]["data"]
    del data["path"]
    try:
        Job.from_api(data)
    except QueueProtocolError:
        return
    raise AssertionError("a claim without a path must be rejected")
