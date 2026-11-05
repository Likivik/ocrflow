"""`stats()` must separate "queued" from "claimable".

The defect this pins is a placement bug, not a logic bug: the SELECT was written
correctly but landed in the ``except`` handler, so the healthy path never set the
key. Callers that asked for ``claimable`` got nothing back and fell through to
``queued`` — which includes a retry sitting inside its backoff window. So the
distinction the code documents had no effect, and a poller that trusted it waited
forever on work that was, by design, not due yet.

Both states are produced here through the real store API, on the module's own
temporary index (see conftest ``_own_state_dir``): ``record_enqueue`` for a job
that is due now, and ``enqueue_retry`` for one scheduled into the future.
"""

import time

import pytest

from nc_ocr_flow import jobstore


@pytest.fixture(autouse=True)
def _clean_store():
    """Each test owns the table. They share one index per module by design; a
    leftover row from a sibling would make the counts mean something else."""
    jobstore._reset_for_tests()
    assert jobstore._ready()
    yield


def _job(path: str, node_id: int = 1) -> dict:
    """A row as the server enqueues it. `status` is not optional: without it the
    store writes NULL and every row lands in the `unknown` bucket."""
    return {
        "path": path,
        "nc_path": path,
        "node_id": node_id,
        "engine": "auto",
        "force": False,
        "status": "queued",
        "created": time.time(),
        "attempts": 0,
    }


def test_claimable_counts_a_due_job():
    jobstore.record_enqueue(_job("/likivik/files/due.pdf"))

    s = jobstore.stats()
    assert s.get("queued") == 1
    assert s.get("claimable") == 1, "a queued job with no backoff is claimable now"


def test_claimable_excludes_a_retry_waiting_out_its_backoff():
    jobstore.record_enqueue(_job("/likivik/files/failed.pdf"))
    parent = jobstore.claim_next()
    assert parent is not None
    # The worker's own failure path: the row becomes 'error' before the retry is
    # scheduled, which is also what lets the new row through the in-flight
    # unique index (same path may not be queued *and* running at once).
    parent["status"] = "error"
    parent["error"] = "engine said no"
    jobstore.record_update(parent)

    # One minute in the future: queued, deliberately unclaimable.
    retry_id = jobstore.enqueue_retry(parent, attempts=1, next_run_at=time.time() + 60)
    assert retry_id is not None

    s = jobstore.stats()
    assert s.get("queued") == 1, "the retry row is queued"
    assert s.get("claimable") == 0, (
        "a retry inside its backoff window must not be reported as claimable — "
        "this is the exact confusion that made a healthy queue look stuck"
    )
    assert jobstore.claim_next() is None, "claim_next must agree with the count"


def test_claimable_becomes_nonzero_when_the_backoff_expires():
    jobstore.record_enqueue(_job("/likivik/files/late.pdf"))
    parent = jobstore.claim_next()
    assert parent is not None
    parent["status"] = "error"
    jobstore.record_update(parent)
    assert jobstore.enqueue_retry(parent, attempts=1, next_run_at=time.time() - 1) is not None

    s = jobstore.stats()
    assert s.get("claimable") == 1, "an expired backoff is claimable again"
    assert jobstore.claim_next() is not None
