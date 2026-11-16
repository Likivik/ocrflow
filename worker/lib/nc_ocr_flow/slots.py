"""Several OCR jobs at once, one process per slot.

PyMuPDF's maintainers are explicit that threading is not supported — MuPDF
keeps a single context, and the GIL was never the reason ("the existence of
the GIL never was a reason why we did not support threading", JorjMcKie,
2026). Recommending a global lock around every fitz call means auditing
every call site in seven modules and getting all of them right forever; a
missed one is a segfault, not an exception.

A fork is cheaper and cannot be got wrong: each slot is a **process** with
its own MuPDF context, its own HTTP session, its own Vision client, its own
SQLite connections and its own registry row. The parent supervises: it
starts them, watches them, and on SIGTERM asks them to stop and joins.

Two consequences worth stating:

* **N slots means N registry rows** (``worker-1``, ``worker-2``, …). That is
  what makes a dead slot's claim recoverable: its heartbeat stops, the app
  sees a stale holder, the claim goes back on the queue. One shared row
  would be beaten by a healthy sibling and hide the dead one's claim.
* **Memory is per slot**, ~600 MB high-water on a scanned document. The
  number in the setting is the number of processes; nothing clamps it, and
  a value the host cannot hold ends in the OOM killer. The startup line says
  what the host offers so the number is not a guess.
"""

from __future__ import annotations

import contextlib
import logging
import multiprocessing
import signal
import threading
import time
from collections.abc import Callable
from typing import Any

from .config import ConfigError, env_int
from .queue_client import QueueClient

log = logging.getLogger(__name__)

DEFAULT_SLOTS = 2
DEFAULT_HEARTBEAT_SECONDS = 60
DEFAULT_SHUTDOWN_SECONDS = 20

# Set by the signal handler, read by the supervisor loop. A module global
# because the handler has no other way to reach the loop; multiprocessing
# keeps it shared with the children.
_STOP = None


def slots_from_env() -> int:
    """``NC_OCR_SLOTS``, default 2, floor 1.

    No ceiling: the value is the operator's instruction. A typo is caught by
    :class:`ConfigError` (not an int), not by silently capping it.
    """
    value = env_int("NC_OCR_SLOTS", DEFAULT_SLOTS)
    if value < 1:
        raise ConfigError(f"NC_OCR_SLOTS must be at least 1, got {value}")
    return value


def slot_worker_name(base: str, index: int) -> str:
    """A distinct registry identity per slot: ``worker`` -> ``worker-1``.

    The app truncates at 64 characters; truncation happens here so two long
    names cannot collide after the app's cut.
    """
    suffix = f"-{index}"
    return f"{base[: 64 - len(suffix)]}{suffix}"


def _blocked(*events: Any) -> bool:
    """True when any of the stop events is set (``None`` counts as not set)."""
    return any(event is not None and event.is_set() for event in events)


def slot_loop(
    client: QueueClient,
    process: Callable[..., dict[str, Any]],
    *,
    idle: float,
    stop: Any,
    gate: Any = None,
    extra_stop: Any = None,
) -> None:
    """Claim, do, report — until ``stop`` (or ``extra_stop``) is set.

    This is the whole worker body. One process runs one copy; the serial
    deployment runs one copy in the main thread, which is why the loop takes
    its collaborators as arguments: the tests drive it with fakes instead of
    patching module globals behind a running pool.
    """
    while not _blocked(stop, extra_stop):
        if gate is not None:
            verdict = gate.check()
            if verdict.blocked:
                # A blocked quota must not fall through to the idle sleep: the
                # bounded gate sleep IS the wait, so a recovered quota is
                # picked up within at most the gate's own cap.
                _sleep(stop, gate.sleep_seconds())
                continue
        try:
            handled = run_once(client, process, gate=gate)
        except Exception as exc:  # never let one job kill the slot
            log.error("slot loop error: %s", exc)
            _sleep(stop, min(idle * 4, 60))
            continue
        if not handled:
            _sleep(stop, idle)


def _sleep(stop: Any, seconds: float) -> None:
    """Sleep that a stop request can cut short."""
    if hasattr(stop, "wait"):
        stop.wait(seconds)
    else:
        time.sleep(seconds)


def run_once(client: QueueClient, process: Callable[..., dict[str, Any]], gate: Any = None) -> bool:
    """One job, or ``False`` when the queue was empty.

    Imported inside the function to keep this module importable without the
    OCR stack: ``slots`` is imported by ``runner`` before the engine checks.
    """
    from .runner import run_once as _run_once

    return _run_once(client, process, gate=gate)


def _shutdown_deadline_seconds() -> float:
    try:
        return float(env_int("NC_OCR_SHUTDOWN_SECONDS", DEFAULT_SHUTDOWN_SECONDS))
    except ConfigError:
        return float(DEFAULT_SHUTDOWN_SECONDS)


def slot_main(
    index: int,
    *,
    base_url: str,
    secret: str,
    worker_name: str,
    idle: float,
    heartbeat: int,
    stop: Any,
) -> None:
    """Body of a child slot: its own identity, client, heartbeat, loop."""
    logging.basicConfig(
        level=logging.getLogger().level or logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    name = slot_worker_name(worker_name, index)
    log.info("slot %d starting as %s", index, name)

    local_stop = threading.Event()

    def _term(_signum, _frame):
        log.info("slot %d: stop requested", index)
        local_stop.set()

    for sig in (signal.SIGTERM, signal.SIGINT):
        # A child is spawned by fork from the parent's main thread, but the
        # suppress keeps this importable from a thread in tests.
        with contextlib.suppress(ValueError):
            signal.signal(sig, _term)

    # Warm the classifier before the first job: the lazy init is a
    # check-then-set, and a cold start inside a job makes the first file
    # slower than every later one.
    try:
        from .classifier import prewarm

        prewarm()
    except Exception as exc:  # a missing model is a per-job error, not a crash
        log.warning("classifier prewarm failed: %s", exc)

    client = QueueClient(base_url=base_url, secret=secret, worker_name=name)

    from .runner import start_heartbeat

    start_heartbeat(client, heartbeat)

    from .webhook_server import _process_file, start_vision_quota_sampler

    start_vision_quota_sampler()

    from . import quota_gate

    slot_loop(
        client,
        _process_file,
        idle=idle,
        stop=stop,
        gate=quota_gate.GATE,
        extra_stop=local_stop,
    )
    log.info("slot %d stopped", index)


class SlotSupervisor:
    """Starts the children, watches them, stops them on request.

    Splitting this out of ``main()`` is what makes the shutdown testable: the
    tests drive a supervisor over trivial children and assert the join
    budget, without a live Nextcloud.
    """

    def __init__(self, *, slots: int, ctx: Any = None) -> None:
        self.slots = slots
        self.ctx = ctx or multiprocessing.get_context("fork")
        self.stop = self.ctx.Event()
        self.processes: list[Any] = []

    def start(self, target: Callable[..., None], **kwargs: Any) -> None:
        for index in range(1, self.slots + 1):
            proc = self.ctx.Process(
                target=target,
                kwargs={"index": index, **kwargs, "stop": self.stop},
                name=f"ocrflow-slot-{index}",
            )
            proc.start()
            self.processes.append(proc)
        global _STOP
        _STOP = self.stop

    def request_stop(self) -> None:
        self.stop.set()

    def wait(self, poll: float = 1.0, on_death: Callable[[Any], None] | None = None) -> int:
        """Block until a stop is requested. Returns 0, or 1 if a slot died.

        A dead slot is not papered over: the unit exits non-zero so systemd
        restarts it. Logging it and carrying on would leave the deployment
        silently running with fewer slots than the operator asked for.
        """
        try:
            while not self.stop.is_set():
                time.sleep(poll)
                for proc in self.processes:
                    if not proc.is_alive() and proc.exitcode not in (0, None):
                        log.error(
                            "slot %s died (exit %s): exiting so the unit restarts",
                            proc.name,
                            proc.exitcode,
                        )
                        if on_death is not None:
                            on_death(proc)
                        self.stop.set()
                        return 1
            return 0
        finally:
            self.join()

    def join(self) -> None:
        """Give the children the shutdown budget, then insist."""
        deadline = time.time() + _shutdown_deadline_seconds()
        for proc in self.processes:
            proc.join(max(0.0, deadline - time.time()))
        for proc in self.processes:
            if proc.is_alive():
                log.warning("%s did not stop in time; terminating", proc.name)
                proc.terminate()
        for proc in self.processes:
            proc.join(5)
            if proc.is_alive():
                proc.kill()
                proc.join(2)

    def alive(self) -> int:
        return sum(1 for proc in self.processes if proc.is_alive())


def stop_requested() -> bool:
    """True once the supervisor asked the children to stop."""
    return _STOP is not None and _STOP.is_set()
