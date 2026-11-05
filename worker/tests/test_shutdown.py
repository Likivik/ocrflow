"""Shutdown is a lifecycle step, not a crash.

Measured consequence of not having it: a SIGTERM left the claimed row at
`status='running'`, `requeue_stale()` only ran on the *next* start, and the job —
a paid Google Vision call in the worst case — was made a second time.

The collaborators are injected rather than patched through module globals: a test
that monkeypatches a module a running thread pool may have already resolved is
testing the patch, not the shutdown. Wiring is covered separately, by identity.
"""

import signal

import pytest

from nc_ocr_flow import runner


def test_shutdown_stops_then_requeues():
    calls: list[str] = []

    runner._graceful_shutdown(
        signal.SIGTERM,
        stop=lambda: calls.append("stop"),
        requeue=lambda: calls.append("requeue") or 1,
    )

    # Order matters: requeueing while the loops still run would hand the job to a
    # worker that is about to die.
    assert calls == ["stop", "requeue"]


def test_a_failing_stop_does_not_skip_the_requeue():
    calls: list[str] = []

    def _boom():
        raise RuntimeError("scheduler refused to stop")

    runner._graceful_shutdown(
        signal.SIGTERM, stop=_boom, requeue=lambda: calls.append("requeue") or 0
    )

    assert calls == ["requeue"], "the job must be handed back even if stop() fails"


def test_a_failing_requeue_does_not_raise():
    """A shutdown path that raises is a shutdown path that hangs."""

    def _boom():
        raise RuntimeError("store is gone")

    runner._graceful_shutdown(signal.SIGTERM, stop=lambda: None, requeue=_boom)


def test_the_defaults_are_the_real_production_calls():
    """Wiring by identity: the injected tests above do not prove what runs in prod."""
    from nc_ocr_flow import jobstore
    from nc_ocr_flow import webhook_server as ws

    assert runner._default_stop() is ws.stop_scheduler
    assert runner._default_requeue() is jobstore.requeue_stale


def test_handler_installs_for_term_and_int(monkeypatch):
    installed: list[int] = []
    monkeypatch.setattr(signal, "signal", lambda sig, handler: installed.append(sig))

    runner._install_signal_handlers()

    assert signal.SIGTERM in installed and signal.SIGINT in installed


def test_install_is_quiet_off_the_main_thread(monkeypatch):
    def _not_main(_sig, _handler):
        raise ValueError("signal only works in main thread")

    monkeypatch.setattr(signal, "signal", _not_main)

    runner._install_signal_handlers()  # no exception: library use stays quiet


def test_handler_shuts_down_then_exits_zero(monkeypatch):
    calls: list[str] = []
    captured = {}

    monkeypatch.setattr(signal, "signal", lambda sig, h: captured.setdefault(sig, h))
    monkeypatch.setattr(runner, "_default_stop", lambda: lambda: calls.append("stop"))
    monkeypatch.setattr(runner, "_default_requeue", lambda: lambda: calls.append("requeue") or 0)

    runner._install_signal_handlers()
    with pytest.raises(SystemExit) as exc:
        captured[signal.SIGTERM](signal.SIGTERM, None)

    assert exc.value.code == 0
    assert calls == ["stop", "requeue"]
