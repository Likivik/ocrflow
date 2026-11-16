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

import os
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


def test_identity_is_label_pid_and_nonce() -> None:
    """`poweredge:809912:3f9a1c` — readable in ps, unique per process."""
    identity = slots.slot_identity("poweredge", pid=809912, nonce="3f9a1c")
    assert identity == "poweredge:809912:3f9a1c"


def test_identity_uses_this_process_and_a_fresh_nonce() -> None:
    first = slots.slot_identity("worker")
    second = slots.slot_identity("worker")
    assert f":{os.getpid()}:" in first
    assert first != second, "a restarted slot must never reuse an identity"


def test_identity_stays_inside_the_app_column() -> None:
    identity = slots.slot_identity("w" * 60)
    assert len(identity) <= 64


def test_a_dead_pid_does_not_collide_across_restarts() -> None:
    """Same pid, different nonce: two lives, two names."""
    a = slots.slot_identity("poweredge", pid=4242, nonce="aaaaaa")
    b = slots.slot_identity("poweredge", pid=4242, nonce="bbbbbb")
    assert a != b


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


# -- resize: grow immediately, shrink only after the job in hand -----------


def _working_child(index, stop, retire, workdir):
    """A child that does one job, then waits. The real loop's shape."""
    import time as _time

    with open(f"{workdir}/job-{index}.done", "w", encoding="utf-8") as handle:
        handle.write("done")
    while not retire.is_set() and not stop.is_set():
        _time.sleep(0.02)


def test_grow_forks_more_slots(tmp_path) -> None:
    import multiprocessing

    supervisor = slots.SlotSupervisor(slots=1, ctx=multiprocessing.get_context("fork"))
    supervisor.start(_working_child, workdir=str(tmp_path))
    try:
        assert supervisor.alive() == 1
        assert supervisor.grow(2) == 2
        deadline = time.time() + 5
        while supervisor.alive() < 3 and time.time() < deadline:
            time.sleep(0.02)
        assert supervisor.alive() == 3
    finally:
        supervisor.request_stop()
        supervisor.wait(poll=0.05)
    assert supervisor.alive() == 0


def test_shrink_lets_the_current_job_finish(tmp_path) -> None:
    """A retired slot completes its work and exits — the job is not dropped."""
    import multiprocessing

    supervisor = slots.SlotSupervisor(slots=2, ctx=multiprocessing.get_context("fork"))
    supervisor.start(_working_child, workdir=str(tmp_path))
    try:
        deadline = time.time() + 5
        while len(list(tmp_path.glob("job-*.done"))) < 2 and time.time() < deadline:
            time.sleep(0.02)
        assert len(list(tmp_path.glob("job-*.done"))) == 2, "both slots did their job"

        assert supervisor.shrink(1) == 1
        deadline = time.time() + 5
        while supervisor.alive() > 1 and time.time() < deadline:
            supervisor.reap()
            time.sleep(0.02)

        assert supervisor.alive() == 1, "one slot retired, one left"
        assert len(list(tmp_path.glob("job-*.done"))) == 2, "no job was lost on the way out"
    finally:
        supervisor.request_stop()
        supervisor.wait(poll=0.05)
    assert supervisor.alive() == 0


def test_shrink_never_asks_more_than_there_are(tmp_path) -> None:
    import multiprocessing

    supervisor = slots.SlotSupervisor(slots=2, ctx=multiprocessing.get_context("fork"))
    supervisor.start(_working_child, workdir=str(tmp_path))
    try:
        assert supervisor.shrink(5) == 2
        assert supervisor.shrink(1) == 0, "already retiring slots are not counted twice"
    finally:
        supervisor.request_stop()
        supervisor.wait(poll=0.05)


def test_resize_to_the_same_number_does_nothing(tmp_path) -> None:
    import multiprocessing

    supervisor = slots.SlotSupervisor(slots=2, ctx=multiprocessing.get_context("fork"))
    supervisor.start(_working_child, workdir=str(tmp_path))
    try:
        supervisor.resize(2)
        assert supervisor.alive() == 2
        assert all(not entry["retire"].is_set() for entry in supervisor.processes)
    finally:
        supervisor.request_stop()
        supervisor.wait(poll=0.05)


# -- the app's answer ------------------------------------------------------


class _FakeResponse:
    def __init__(self, status_code: int, body: dict) -> None:
        self.status_code = status_code
        self._body = body
        self.request = type("Req", (), {"method": "GET", "path_url": "/worker/slots"})()

    def json(self):
        return self._body


class _FakeSession:
    def __init__(self, response) -> None:
        self._response = response
        self.calls: list[tuple[str, dict]] = []

    def get(self, url, headers=None, timeout=None):
        self.calls.append((url, headers or {}))
        return self._response


def test_client_reads_the_slot_setting_from_the_app() -> None:
    from nc_ocr_flow.queue_client import QueueClient

    session = _FakeSession(
        _FakeResponse(200, {"ocs": {"meta": {"statuscode": 200}, "data": {"slots": 3}}})
    )
    client = QueueClient(base_url="http://app", secret="s", session=session)

    assert client.slots() == 3
    url, headers = session.calls[0]
    assert url.endswith("/worker/slots")
    assert headers.get("OCS-APIRequest") == "true"


def test_client_returns_none_when_the_app_cannot_say() -> None:
    from nc_ocr_flow.queue_client import QueueClient

    for response in (
        _FakeResponse(404, {}),
        _FakeResponse(200, {"ocs": {"meta": {"statuscode": 200}, "data": {}}}),
        _FakeResponse(200, {"ocs": {"meta": {"statuscode": 200}, "data": {"slots": 0}}}),
    ):
        client = QueueClient(base_url="http://app", secret="s", session=_FakeSession(response))
        assert client.slots() is None, "an unreadable answer must leave the pool alone"


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
            supervisor.start(lambda index, stop, retire: None, workdir=".")
            supervisor.join()
    finally:
        stop_sleeper.set()
        thread.join(3)

    assert "forking with" in caplog.text
