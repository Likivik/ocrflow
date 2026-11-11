"""The vision-quota claim gate: a temporary shortage must not burn attempts.

Claiming a job increments its attempt counter on the app side. A worker that
keeps claiming while the Vision quota is exhausted turns "the quota resets at
midnight" into a pile of terminal ``error`` rows — retries are for failures, a
rate limit is not one. The gate below holds the last quota sample and answers
one question: may this process claim right now?

Fail-open is the doctrine when the sample is missing or stale: refusing to
claim because a monitoring metric is down would stop OCR entirely, which is a
worse outage than the shortage the gate guards against.
"""

from __future__ import annotations

import logging
import time

import pytest
from fastapi.testclient import TestClient

from nc_ocr_flow import quota_gate as qg
from nc_ocr_flow import runner
from nc_ocr_flow.health import create_app


class FakeClient:
    """Counts claims: a claim is an attempts+1 on the app side."""

    def __init__(self, job=None):
        self.job = job
        self.claims = 0

    def claim(self):
        self.claims += 1
        return self.job

    def result(self, *_a, **_k):
        pass

    def failure(self, *_a, **_k):
        pass


def _gate(max_age=1800.0) -> qg.QuotaGate:
    return qg.QuotaGate(max_age=max_age)


def _nope(path, file_id, **_):
    raise AssertionError("process must not run while quota blocks claiming")


# --- the gate decides, the runner obeys -------------------------------------


def test_exhausted_quota_blocks_the_claim_entirely():
    """remaining <= 0 and fresh: run_once must not call claim at all."""
    gate = _gate()
    gate.set_enabled(True)
    gate.update(remaining=0)

    client = FakeClient()
    handled = runner.run_once(client, _nope, gate=gate)

    assert handled is False
    assert client.claims == 0, "a claim would have incremented attempts app-side"


def test_missing_sample_fails_open():
    """No sample yet (metric never answered): claim as today."""
    gate = _gate()
    gate.set_enabled(True)

    client = FakeClient(job=None)
    handled = runner.run_once(client, lambda p, f, **_: {}, gate=gate)

    assert handled is False  # empty queue, not a blocked claim
    assert client.claims == 1


def test_stale_sample_fails_open():
    """Sample older than 2x the sampling interval: the metric is down."""
    gate = _gate(max_age=100.0)
    gate.set_enabled(True)
    gate.update(remaining=0)
    # age the sample past its useful life
    with gate._lock:
        gate._sample = qg.QuotaSample(remaining=0, sampled_at=time.time() - 500)

    client = FakeClient(job=None)
    runner.run_once(client, lambda p, f, **_: {}, gate=gate)

    assert client.claims == 1


def test_disabled_gate_never_blocks():
    gate = _gate()
    gate.set_enabled(False)
    gate.update(remaining=0)

    client = FakeClient(job=None)
    runner.run_once(client, lambda p, f, **_: {}, gate=gate)

    assert client.claims == 1


def test_recovered_quota_unblocks_promptly():
    gate = _gate()
    gate.set_enabled(True)
    gate.update(remaining=0)
    assert gate.check().blocked

    gate.update(remaining=120)
    verdict = gate.check()
    assert not verdict.blocked
    # and the bounded sleep while blocked never exceeds the recovery window
    assert gate.sleep_seconds() <= 60.0


def test_blocked_sleep_is_bounded():
    """Never busy-loop, never sleep unboundedly: 1s <= sleep <= 60s."""
    gate = _gate(max_age=1800.0)
    gate.set_enabled(True)
    gate.update(remaining=0)

    assert 1.0 <= gate.sleep_seconds() <= 60.0

    with gate._lock:
        gate._sample = qg.QuotaSample(remaining=0, sampled_at=time.time() - 1795)
    assert 1.0 <= gate.sleep_seconds() <= 60.0


# --- log on transitions only, not per iteration ------------------------------


def test_transition_logged_once_not_per_loop(caplog):
    gate = _gate()
    gate.set_enabled(True)
    gate.update(remaining=0)

    with caplog.at_level(logging.WARNING, logger="nc_ocr_flow.quota_gate"):
        for _ in range(5):
            gate.check()

    blocked_msgs = [r for r in caplog.records if "NOT claiming" in r.message]
    assert len(blocked_msgs) == 1, "one announcement per transition, not per loop"

    gate.update(remaining=50)
    with caplog.at_level(logging.INFO, logger="nc_ocr_flow.quota_gate"):
        for _ in range(5):
            gate.check()

    recovered = [r for r in caplog.records if "claiming jobs again" in r.message]
    assert len(recovered) == 1


def test_fail_open_logs_the_reason_once(caplog):
    gate = _gate()
    gate.set_enabled(True)  # enabled, but no sample ever arrived

    with caplog.at_level(logging.WARNING, logger="nc_ocr_flow.quota_gate"):
        for _ in range(4):
            verdict = gate.check()

    assert not verdict.blocked
    warnings = [r for r in caplog.records if "failing OPEN" in r.message]
    assert len(warnings) == 1
    assert "sample" in warnings[0].message


# --- the state is visible on the probe surface -------------------------------


def test_heartbeat_exposes_the_quota_state():
    gate = _gate()
    gate.set_enabled(True)
    gate.update(remaining=7)

    app = create_app(probe=lambda: {"ready": True, "jobs_depth": 0, "error": None}, quota=gate)
    with TestClient(app) as client:
        body = client.get("/heartbeat").json()

    quota = body["vision_quota"]
    assert quota["enabled"] is True
    assert quota["remaining"] == 7
    assert quota["sampled_at"] is not None
    assert quota["blocked"] is False
    # no secrets: the snapshot carries numbers and timestamps only
    assert set(quota) <= {"enabled", "remaining", "sampled_at", "blocked"}


def test_module_gate_is_shared_and_single_writer():
    """The sampler updates the module-level gate; the runner reads it."""
    assert isinstance(qg.GATE, qg.QuotaGate)
    before = qg.GATE.snapshot()
    qg.GATE.update(remaining=3)
    try:
        after = qg.GATE.snapshot()
        assert after["remaining"] == 3
        assert after["sampled_at"] >= (before["sampled_at"] or 0)
    finally:
        # leave the shared state as it was for other tests
        with qg.GATE._lock:
            qg.GATE._sample = None
            qg.GATE._blocked_state = None
