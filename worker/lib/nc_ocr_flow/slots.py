"""Several OCR jobs at once, one process per slot.

PyMuPDF's maintainers are explicit that threading is not supported — MuPDF
keeps a single context, and the GIL was never the reason ("the existence of
the GIL never was a reason why we did not support threading", JorjMcKie,
2026). A global lock around every fitz call would mean auditing call sites in
seven modules forever, with a segfault as the failure mode for the one that
was missed.

A fork is cheaper and cannot be got wrong: each slot is a **process** with its
own MuPDF context, its own HTTP session, its own Vision client, its own SQLite
connections and its own registry row. The parent supervises: it starts them,
resizes the pool when the app's setting changes, watches them, and on SIGTERM
asks them to stop and joins.

Three consequences worth stating:

* **N slots means N registry rows.** That is what makes a dead slot's claim
  recoverable: its heartbeat stops, the app sees a stale holder, the claim
  goes back on the queue. One shared row would be beaten by a healthy sibling
  and hide the dead one's claim forever.
* **Resizing never costs a redo.** The stop check sits between jobs. Growing
  forks a slot; shrinking sets a retire flag and lets the slot finish the file
  it holds, report it, and exit.
* **Identity is per process, not per slot.** A slot is named
  ``poweredge:809912:3f9a1c`` — the operator's label, the pid, and six hex of
  nonce.

Why that shape: Sidekiq's process identity is the same tuple
(``hostname:pid:process_nonce``, ``lib/sidekiq/component.rb:74``) because a
pid gets reused, so a per-boot nonce disambiguates it. OpenTelemetry's
``service.instance.id`` states the rule — it *"MUST be unique for each
instance of the same service.namespace,service.name pair"* — and keeps it a
separate field from the human-readable name. The start time is deliberately
not in the identity: the registry already stores ``started_at``, and clocks in
identifiers are how you get duplicates from a host with a skewed clock. Six
hex of nonce is 16.7 M values; a collision needs the same pid *and* the same
nonce, and would only ever produce a confusing log line — claims key on the
registry row id, never on this string.
"""

from __future__ import annotations

import contextlib
import logging
import multiprocessing
import os
import secrets
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
DEFAULT_RESIZE_POLL_SECONDS = 10.0

# Set by the signal handler, read by the supervisor loop. A module global
# because the handler has no other way to reach the loop; multiprocessing
# keeps it shared with the children.
_STOP = None


def slots_from_env() -> int:
    """``NC_OCR_SLOTS``, default 2, floor 1 — the *start-up* value.

    Once running, the app's ``worker_slots`` setting decides; this seeds the
    first pool before the first poll. No ceiling: the value is the operator's
    instruction, and a typo is caught by :class:`ConfigError`, not silently
    capped.
    """
    value = env_int("NC_OCR_SLOTS", DEFAULT_SLOTS)
    if value < 1:
        raise ConfigError(f"NC_OCR_SLOTS must be at least 1, got {value}")
    return value


def slot_identity(base: str, *, pid: int | None = None, nonce: str | None = None) -> str:
    """``base:pid:nonce`` — unique per process lifetime, readable in `ps`.

    ``base`` is the operator's label (``NC_OCR_WORKER_NAME``, the host name by
    default). Truncated to 28 characters so the result stays inside the app's
    64-character column with room for the pid and nonce.
    """
    label = (base or "worker")[:28]
    token = nonce or secrets.token_hex(3)
    return f"{label}:{os.getpid() if pid is None else pid}:{token}"


def _blocked(*events: Any) -> bool:
    """True when any of the stop events is set (``None`` counts as not set)."""
    return any(event is not None and event.is_set() for event in events)


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


def slot_loop(
    client: QueueClient,
    process: Callable[..., dict[str, Any]],
    *,
    idle: float,
    stop: Any,
    gate: Any = None,
    extra_stop: Any = None,
) -> None:
    """Claim, do, report — until a stop event is set.

    The stop check sits at the top of the loop, **between** jobs. That is what
    makes a retire free: a slot always finishes and reports the job it holds
    before it looks for the flag.
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


def _shutdown_deadline_seconds() -> float:
    with contextlib.suppress(ConfigError, TypeError, ValueError):
        return float(env_int("NC_OCR_SHUTDOWN_SECONDS", DEFAULT_SHUTDOWN_SECONDS))
    return float(DEFAULT_SHUTDOWN_SECONDS)


class _AnyEvent:
    """Several stop events seen as one, without spawning a helper thread."""

    def __init__(self, *events: Any) -> None:
        self._events = [event for event in events if event is not None]

    def is_set(self) -> bool:
        return any(event.is_set() for event in self._events)

    def wait(self, timeout: float | None = None) -> bool:
        deadline = None if timeout is None else time.monotonic() + timeout
        while not self.is_set():
            if deadline is not None and time.monotonic() >= deadline:
                return False
            time.sleep(0.02)
        return True


def slot_main(
    index: int,
    *,
    base_url: str,
    secret: str,
    worker_name: str,
    idle: float,
    heartbeat: int,
    stop: Any,
    retire: Any = None,
) -> None:
    """Body of a child slot: its own identity, client, heartbeat, loop."""
    logging.basicConfig(
        level=logging.getLogger().level or logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    identity = slot_identity(worker_name)
    log.info("slot %d starting as %s", index, identity)

    local_stop = threading.Event()

    def _term(_signum, _frame):
        log.info("slot %d (%s): stop requested", index, identity)
        local_stop.set()

    for sig in (signal.SIGTERM, signal.SIGINT):
        # A child is forked from the parent's main thread, but the suppress
        # keeps this importable from a thread in tests.
        with contextlib.suppress(ValueError):
            signal.signal(sig, _term)

    # Warm the classifier before the first job: the lazy init is a
    # check-then-set, and a cold start inside a job makes the first file
    # slower than every later one.
    with contextlib.suppress(Exception):
        from .classifier import prewarm

        prewarm()

    client = QueueClient(base_url=base_url, secret=secret, worker_name=identity)

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
        extra_stop=_AnyEvent(local_stop, retire),
    )
    log.info("slot %d (%s) stopped", index, identity)


class SlotSupervisor:
    """Starts the children, resizes them, stops them on request.

    Splitting this out of ``main()`` is what makes it testable: the tests drive
    a supervisor over trivial children and assert the join budget, the resize
    behaviour and the fork-safety warning, without a live Nextcloud.
    """

    def __init__(
        self,
        *,
        slots: int,
        ctx: Any = None,
        target_source: Callable[[], int | None] | None = None,
        resize_poll_seconds: float | None = None,
    ) -> None:
        self.slots = slots
        self.ctx = ctx or multiprocessing.get_context("fork")
        self.stop = self.ctx.Event()
        # Where the number comes from while running (the app's setting). None
        # keeps the pool fixed at the start-up value.
        self.target_source = target_source
        self.resize_poll_seconds = (
            DEFAULT_RESIZE_POLL_SECONDS if resize_poll_seconds is None else resize_poll_seconds
        )
        # Each entry: process, retire event, index. The retire flag is what
        # makes a shrink free — the child finishes its job before it looks.
        self.processes: list[dict[str, Any]] = []
        self._next_index = 1
        self._fork_kwargs: dict[str, Any] = {}
        self._fork_target: Callable[..., None] | None = None

    # -- lifecycle ---------------------------------------------------------

    def start(self, target: Callable[..., None], **kwargs: Any) -> None:
        # Fork safety: a thread that holds a lock at fork time leaves that lock
        # held forever in the child. The parent must be single-threaded — the
        # heartbeat and the quota sampler belong to the slots, not to it.
        live = threading.active_count()
        if live > 1:
            log.warning(
                "forking with %d live thread(s) in the parent: a lock held at "
                "fork time would stay held in every child",
                live - 1,
            )
        self._fork_target = target
        self._fork_kwargs = dict(kwargs)
        self._fork(self.slots)
        global _STOP
        _STOP = self.stop

    def _fork(self, count: int) -> None:
        target = self._fork_target
        if target is None:
            raise RuntimeError("start() must be called before growing the pool")
        for _ in range(count):
            index = self._next_index
            self._next_index += 1
            retire = self.ctx.Event()
            proc = self.ctx.Process(
                target=target,
                kwargs={**self._fork_kwargs, "index": index, "stop": self.stop, "retire": retire},
                name=f"ocrflow-slot-{index}",
            )
            proc.start()
            self.processes.append({"process": proc, "retire": retire, "index": index})
            log.info("slot %d forked (pid %s)", index, proc.pid)

    def request_stop(self) -> None:
        self.stop.set()

    # -- resize ------------------------------------------------------------

    def grow(self, count: int) -> int:
        """Fork ``count`` more slots. Returns how many were started."""
        if count <= 0:
            return 0
        self._fork(count)
        return count

    def shrink(self, count: int) -> int:
        """Ask ``count`` slots to retire. They finish their job first.

        They leave ``processes`` when they actually exit, not here: a slot
        still working a file is still a slot.
        """
        asked = 0
        for entry in self.processes:
            if asked >= count:
                break
            if entry["process"].is_alive() and not entry["retire"].is_set():
                entry["retire"].set()
                log.info("slot %d retiring after its current job", entry["index"])
                asked += 1
        return asked

    def resize(self, target: int) -> None:
        """Move towards ``target`` slots; ``target`` below 1 is ignored.

        Growth is immediate. Shrinkage is a request, so the process count
        reaches the target only after the retiring slots finish their jobs —
        and no claim is dropped in the meantime.
        """
        if target < 1:
            return
        retiring = sum(
            1
            for entry in self.processes
            if entry["retire"].is_set() and entry["process"].is_alive()
        )
        effective = self.alive() - retiring
        if effective < target:
            self.grow(target - effective)
        elif effective > target:
            self.shrink(effective - target)

    # -- supervision -------------------------------------------------------

    def reap(self) -> bool:
        """Drop finished slots. Returns False when one died unasked.

        A slot that exits without being retired is a failure: the deployment
        would otherwise run with fewer slots than the operator asked for while
        looking healthy.
        """
        healthy = True
        for entry in list(self.processes):
            proc = entry["process"]
            if proc.is_alive():
                continue
            retired = entry["retire"].is_set()
            self.processes.remove(entry)
            if retired:
                log.info("slot %d retired cleanly", entry["index"])
            elif proc.exitcode not in (0, None):
                log.error(
                    "slot %s died (exit %s): exiting so the unit restarts",
                    proc.name,
                    proc.exitcode,
                )
                healthy = False
            else:
                log.warning("slot %s exited on its own (exit %s)", proc.name, proc.exitcode)
        return healthy

    def wait(self, poll: float = 1.0, on_death: Callable[[Any], None] | None = None) -> int:
        """Block until a stop is requested. Returns 0, or 1 if a slot died."""
        last_poll = 0.0
        try:
            while not self.stop.is_set():
                time.sleep(poll)
                if not self.reap():
                    self.stop.set()
                    if on_death is not None:
                        on_death(self.processes)
                    return 1
                source = self.target_source
                due = source is not None and (
                    time.monotonic() - last_poll >= self.resize_poll_seconds
                )
                if due and source is not None:
                    last_poll = time.monotonic()
                    try:
                        target = source()
                    except Exception as exc:  # the app answered badly; keep working
                        log.warning("slots: could not read the setting (%s)", exc)
                        target = None
                    if target is not None:
                        self.resize(int(target))
            return 0
        finally:
            self.join()

    def join(self) -> None:
        """Give the children the shutdown budget, then insist."""
        deadline = time.time() + _shutdown_deadline_seconds()
        for entry in self.processes:
            entry["process"].join(max(0.0, deadline - time.time()))
        for entry in self.processes:
            if entry["process"].is_alive():
                log.warning("%s did not stop in time; terminating", entry["process"].name)
                entry["process"].terminate()
        for entry in self.processes:
            entry["process"].join(5)
            if entry["process"].is_alive():
                entry["process"].kill()
                entry["process"].join(2)

    def alive(self) -> int:
        return sum(1 for entry in self.processes if entry["process"].is_alive())


def stop_requested() -> bool:
    """True once the supervisor asked the children to stop."""
    return _STOP is not None and _STOP.is_set()
