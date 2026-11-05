"""Ownership of the background loops.

The worker pool, the catch-up pass and the quota loop used to be module-level
globals of :mod:`nc_ocr_flow.webhook_server` expressed as "start a thread at
import". Two things went wrong with that, both measured:

* Importing the server started threads. Every importer — the runner, a test, a
  one-off script that wanted ``_process_file`` — got a scheduler it never asked
  for, and in tests those threads claimed jobs across test boundaries. The suite
  failed about one run in five, a different test each time.
* The bookkeeping lived in the same module the state described. Reloading that
  module (``importlib.reload``, which the credential tests need because the DAV
  identity is read from the environment at import) rebuilt the counters from
  scratch while the threads kept running, so ``generation`` said 0, ``running``
  said False, and two live workers were still claiming from a queue nobody
  thought was being served.

State therefore lives here, in a module with no import-time side effects that
nothing reloads, and it is handed the loops rather than knowing about them.

A generation, not a liveness check. Stopping means "your generation is over", so
a worker stuck inside a slow cloud call cannot come back and claim work on behalf
of the next run — and a restart is never a silent no-op just because some thread
happens to still be alive.
"""

from __future__ import annotations

import logging
import threading
import time
from collections.abc import Callable, Mapping

log = logging.getLogger("nc_ocr_flow.scheduler")

#: A loop takes the generation it was started for. It runs while its generation is
#: current and the scheduler has not been stopped.
Loop = Callable[[int], None]


class Scheduler:
    """Threads, their shutdown flag, and which generation is current."""

    def __init__(self) -> None:
        self.shutdown = threading.Event()
        self.threads: dict[str, threading.Thread] = {}
        self.generation = 0
        self.running = False

    def is_current(self, gen: int) -> bool:
        """True while ``gen`` is the generation that should be running."""
        return not self.shutdown.is_set() and gen == self.generation

    def start(self, loops: Mapping[str, Loop], before: Callable[[], None] | None = None) -> None:
        """Start ``loops`` keyed by thread name. Idempotent while running."""
        if self.running:
            return
        self.generation += 1
        self.shutdown.clear()
        self.running = True
        if before is not None:
            try:
                before()
            except Exception:
                log.exception("scheduler: startup work failed")

        gen = self.generation
        for name, target in loops.items():
            # A same-named thread from a previous generation can still be alive
            # (stuck in a slow call). Keep it registered until it is joined, or
            # stop() would lose track of it entirely.
            old = self.threads.get(name)
            if old is not None and old.is_alive():
                self.threads[f"{name}#retired"] = old
            thread = threading.Thread(target=target, args=(gen,), daemon=True, name=name)
            self.threads[name] = thread
            thread.start()
        log.info("scheduler: started %s (generation %d)", ", ".join(loops), gen)

    def stop(self, timeout: float = 3.0, wake: threading.Event | None = None) -> None:
        """Retire the current generation and join, bounded.

        Bounded because a loop can be inside a cloud OCR call; the threads are
        daemons, so a straggler dies with the process instead of holding up a
        shutdown or a test teardown. ``wake`` exists because an idle worker
        blocks on an event, not on a poll: without it a join waits out the whole
        idle interval.
        """
        self.running = False
        self.shutdown.set()
        if wake is not None:
            wake.set()
        deadline = time.time() + timeout
        for name, thread in list(self.threads.items()):
            thread.join(max(0.0, deadline - time.time()))
            if not thread.is_alive():
                self.threads.pop(name, None)


#: The one instance. Imported by the server, shared with the tests.
scheduler = Scheduler()
