"""Slots: several jobs at once, one process each.

The rules pinned here are the ones that decide whether a second slot is a
speed-up or a mess:

* two slots never handle the same job;
* a slot that hits an error keeps working (one bad document is not a dead
  worker);
* a blocked quota gate never spends a claim attempt;
* a stop request ends the loop promptly;
* the classifier is built once, not once per racing slot.

Nothing here needs a live Nextcloud: the loop takes its collaborators as
arguments, so the fakes are the whole harness.
"""

from __future__ import annotations

import sys
import threading
import time

import pytest

from nc_ocr_flow import classifier, slots


class _Queue:
    """A queue that hands every id out exactly once, under a lock."""

    def __init__(self, ids: list[int]) -> None:
        self._ids = list(ids)
        self._lock = threading.Lock()
        self.handled: list[int] = []

    def take(self) -> int | None:
        with self._lock:
            return self._ids.pop(0) if self._ids else None

    def record(self, job_id: int) -> None:
        with self._lock:
            self.handled.append(job_id)


def _install_fake_run_once(monkeypatch, queue: _Queue, *, sleep: float = 0.01, boom_on=()):
    def fake_run_once(client, process, gate=None):
        job_id = queue.take()
        if job_id is None:
            return False
        time.sleep(sleep)
        if job_id in boom_on:
            raise RuntimeError(f"document {job_id} is unreadable")
        queue.record(job_id)
        return True

    monkeypatch.setattr(slots, "run_once", fake_run_once)


def test_slots_from_env_defaults_to_two(monkeypatch) -> None:
    monkeypatch.delenv("NC_OCR_SLOTS", raising=False)
    assert slots.slots_from_env() == 2


def test_slots_from_env_reads_the_instruction(monkeypatch) -> None:
    monkeypatch.setenv("NC_OCR_SLOTS", "7")
    assert slots.slots_from_env() == 7


def test_slots_from_env_rejects_zero(monkeypatch) -> None:
    monkeypatch.setenv("NC_OCR_SLOTS", "0")
    with pytest.raises(slots.ConfigError):
        slots.slots_from_env()


def test_slot_worker_names_are_distinct_and_bounded() -> None:
    names = [slots.slot_worker_name("w" * 64, i) for i in range(1, 4)]
    assert names == [("w" * 62) + "-1", ("w" * 62) + "-2", ("w" * 62) + "-3"]
    assert all(len(name) <= 64 for name in names)
    assert len(set(names)) == 3


def test_two_slots_never_handle_the_same_job(monkeypatch) -> None:
    queue = _Queue(list(range(1, 41)))
    _install_fake_run_once(monkeypatch, queue)
    stop = threading.Event()

    def _slot() -> None:
        slots.slot_loop(None, lambda **kw: {}, idle=0.001, stop=stop)

    threads = [threading.Thread(target=_slot) for _ in range(2)]
    for thread in threads:
        thread.start()
    deadline = time.time() + 10
    while len(queue.handled) < 40 and time.time() < deadline:
        time.sleep(0.01)
    stop.set()
    for thread in threads:
        thread.join(5)

    assert sorted(queue.handled) == list(range(1, 41))
    assert len(queue.handled) == len(set(queue.handled))


def test_a_failing_job_does_not_kill_the_slot(monkeypatch) -> None:
    queue = _Queue([1, 2, 3])
    _install_fake_run_once(monkeypatch, queue, boom_on=(2,))
    stop = threading.Event()

    def _runner() -> None:
        slots.slot_loop(None, lambda **kw: {}, idle=0.001, stop=stop)

    thread = threading.Thread(target=_runner)
    thread.start()
    deadline = time.time() + 5
    while len(queue.handled) < 2 and time.time() < deadline:
        time.sleep(0.01)
    stop.set()
    thread.join(5)

    assert queue.handled == [1, 3]


def test_a_stop_request_ends_the_loop(monkeypatch) -> None:
    queue = _Queue([])
    _install_fake_run_once(monkeypatch, queue)
    stop = threading.Event()
    started = time.time()
    timer = threading.Timer(0.2, stop.set)
    timer.start()

    slots.slot_loop(None, lambda **kw: {}, idle=0.05, stop=stop)

    assert time.time() - started < 2
    timer.cancel()


def test_a_blocked_quota_gate_does_not_claim(monkeypatch) -> None:
    called = {"claim": 0}

    def fake_run_once(client, process, gate=None):
        called["claim"] += 1
        return False

    monkeypatch.setattr(slots, "run_once", fake_run_once)
    stop = threading.Event()

    class _Blocked:
        blocked = True

    class _Gate:
        def check(self):
            return _Blocked()

        def sleep_seconds(self):
            return 0.05

    timer = threading.Timer(0.2, stop.set)
    timer.start()
    slots.slot_loop(None, lambda **kw: {}, idle=0.01, stop=stop, gate=_Gate())
    timer.cancel()

    assert called["claim"] == 0


def test_classifier_prewarm_builds_one_session(monkeypatch) -> None:
    class _FakeOrt:
        calls = 0

        @staticmethod
        def InferenceSession(_path, providers=None):
            _FakeOrt.calls += 1
            return object()

    monkeypatch.setitem(sys.modules, "onnxruntime", _FakeOrt)
    monkeypatch.setattr(classifier, "_MOBILENET_SESSION", None)

    classifier.prewarm()
    classifier.prewarm()

    assert _FakeOrt.calls == 1, "the session must be built once, not per call"


def test_classifier_prewarm_is_safe_under_concurrency(monkeypatch) -> None:
    class _SlowOrt:
        calls = 0
        lock = threading.Lock()

        @staticmethod
        def InferenceSession(_path, providers=None):
            time.sleep(0.05)
            with _SlowOrt.lock:
                _SlowOrt.calls += 1
            return object()

    monkeypatch.setitem(sys.modules, "onnxruntime", _SlowOrt)
    monkeypatch.setattr(classifier, "_MOBILENET_SESSION", None)

    threads = [threading.Thread(target=classifier.prewarm) for _ in range(4)]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join(5)

    assert _SlowOrt.calls == 1, "four racing slots must still build one session"


def test_supervisor_reports_a_dead_slot() -> None:
    """A slot that exits non-zero makes the unit exit non-zero.

    Silently running on with fewer slots than the operator asked for is the
    failure this guards: the deployment would look healthy at half speed.
    """
    import multiprocessing

    ctx = multiprocessing.get_context("fork")
    supervisor = slots.SlotSupervisor(slots=2, ctx=ctx)

    def boom(index: int, stop) -> None:
        raise SystemExit(3)

    supervisor.start(boom)
    code = supervisor.wait(poll=0.05)

    assert code == 1
    assert supervisor.alive() == 0


def test_supervisor_warns_when_forking_with_live_threads(caplog) -> None:
    """A thread holding a lock at fork time leaves it held in every child.

    The parent must be single-threaded: heartbeat and quota sampling belong to
    the slots. This pins the warning so the trap cannot be reintroduced
    silently — it is exactly how the Phase 1 sampler ended up in the parent.
    """
    import logging
    import multiprocessing

    ctx = multiprocessing.get_context("fork")
    supervisor = slots.SlotSupervisor(slots=1, ctx=ctx)
    stop_sleeper = threading.Event()

    def _sleeper() -> None:
        stop_sleeper.wait(2)

    thread = threading.Thread(target=_sleeper)
    thread.start()
    try:
        with caplog.at_level(logging.WARNING):
            supervisor.start(lambda index, stop: None)
            supervisor.join()
    finally:
        stop_sleeper.set()
        thread.join(3)

    assert "forking with" in caplog.text
