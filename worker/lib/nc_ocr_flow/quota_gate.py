"""The vision-quota claim gate: one state object, one writer, fail-open.

Claiming a job increments its attempt counter on the app side, so a worker
that keeps claiming through a Vision-quota exhaustion spends ``MAX_ATTEMPTS``
on every queued job and a temporary shortage becomes a pile of terminal
``error`` rows. Retries are for failures; "the quota resets at midnight" is
not a failure. This module answers exactly one question for the claim loop:
**may this process claim a job right now?**

The rules, in order:

* Quota gating disabled (no Vision credentials, or the configured engine
  cannot use them) -> always open.
* No sample yet, or the sample is older than ``2 x`` the sampling interval ->
  **fail open** with one logged warning naming the reason. Not claiming
  because a monitoring metric is unavailable would stop OCR entirely, which
  is a worse outage than the shortage the gate guards against.
* Fresh sample with ``remaining <= 0`` -> **blocked**: do not claim, sleep a
  bounded amount, re-check.

The single writer is the quota sampler (``webhook_server._vision_quota_once``):
nothing else calls :meth:`QuotaGate.update`. Transition announcements are
logged once per blocked/unblocked change, never once per loop iteration —
a 15-minute outage must not produce a thousand identical lines.
"""

from __future__ import annotations

import logging
import threading
import time
from dataclasses import dataclass

log = logging.getLogger(__name__)

#: While blocked, never sleep longer than this: a recovered quota must be
#: picked up quickly, not at the next sampling interval.
MAX_SLEEP_S = 60.0
#: ...and never busy-loop while waiting for the sample to recover.
MIN_SLEEP_S = 1.0


def default_max_age() -> float:
    """A sample's useful life: twice the sampling interval.

    Read from the same knob the sampler uses (``webhook_server`` owns the
    loop; this module must not import it — the server imports the gate, so
    the dependency points one way only).
    """
    from .config import env_float

    return 2.0 * env_float("NC_OCR_VISION_QUOTA_INTERVAL", 900.0)


@dataclass(frozen=True)
class QuotaSample:
    """The last quota reading. ``remaining`` is in requests/min."""

    remaining: int
    sampled_at: float


@dataclass(frozen=True)
class QuotaVerdict:
    """The gate's answer. ``reason`` is empty when claiming may proceed."""

    blocked: bool
    reason: str


class QuotaGate:
    """Latest quota sample + the decision built on it. Thread-safe."""

    def __init__(self, max_age: float | None = None) -> None:
        self._lock = threading.Lock()
        self._enabled: bool | None = None  # None: not yet configured
        self._sample: QuotaSample | None = None
        self._max_age = max_age if max_age is not None else default_max_age()
        self._announced_blocked: bool | None = None
        self._warned_fail_open = False

    # -- the writer ------------------------------------------------------

    def set_enabled(self, enabled: bool | None) -> None:
        """Declare whether quota gating applies to this process at all."""
        with self._lock:
            self._enabled = enabled

    def update(self, remaining: int) -> None:
        """Record a fresh sample. Only the quota sampler calls this."""
        with self._lock:
            self._sample = QuotaSample(remaining=remaining, sampled_at=time.time())
            # A live sample clears the fail-open warning so a later outage
            # of the metric is announced again rather than silenced forever.
            self._warned_fail_open = False

    # -- the readers -----------------------------------------------------

    def check(self, now: float | None = None) -> QuotaVerdict:
        """May this process claim right now? Logs on transitions only."""
        now = time.time() if now is None else now
        with self._lock:
            blocked = False
            reason = ""
            if not self._enabled:
                pass  # gating off: claim as today, and stay quiet about it
            elif self._sample is None:
                reason = "no quota sample yet (the metric has never answered)"
            else:
                age = now - self._sample.sampled_at
                if age > self._max_age:
                    reason = (
                        f"quota sample is stale ({age:.0f}s old, "
                        f"max {self._max_age:.0f}s): the metric is unavailable"
                    )
                elif self._sample.remaining <= 0:
                    blocked = True
                    reason = f"vision quota exhausted (remaining={self._sample.remaining})"

            if reason and not blocked and not self._warned_fail_open:
                log.warning(
                    "vision quota: failing OPEN, claiming anyway — %s; "
                    "not claiming would stop OCR because a monitoring metric "
                    "is down, which is worse",
                    reason,
                )
                self._warned_fail_open = True

            if blocked and self._announced_blocked is not True:
                log.warning(
                    "vision quota exhausted: NOT claiming jobs until the "
                    "sample recovers — %s (a claimed job would spend one of "
                    "its limited attempts on a rate limit)",
                    reason,
                )
            elif not blocked and self._announced_blocked is True:
                remaining = self._sample.remaining if self._sample else "?"
                log.info(
                    "vision quota recovered (remaining=%s): claiming jobs again",
                    remaining,
                )
            self._announced_blocked = blocked
            return QuotaVerdict(blocked=blocked, reason=reason)

    def sleep_seconds(self, now: float | None = None) -> float:
        """How long to sleep while blocked. Bounded, never zero.

        Sleeping past the point where the sample goes stale is pointless —
        there is nothing new to re-check before the sampler runs again — so
        the bound is "seconds until the sample is worth re-examining",
        clamped to ``[MIN_SLEEP_S, MAX_SLEEP_S]``.
        """
        now = time.time() if now is None else now
        with self._lock:
            if not self._enabled or self._sample is None:
                return MIN_SLEEP_S
            until_stale = self._max_age - (now - self._sample.sampled_at)
            return max(MIN_SLEEP_S, min(MAX_SLEEP_S, until_stale))

    def snapshot(self) -> dict[str, object]:
        """Probe-surface view: numbers and timestamps, no secrets."""
        with self._lock:
            sample = self._sample
            return {
                "enabled": bool(self._enabled),
                "remaining": sample.remaining if sample else None,
                "sampled_at": sample.sampled_at if sample else None,
                "blocked": self._announced_blocked is True,
            }


#: The process-wide gate. The quota sampler (the existing loop in
#: ``webhook_server``) is the only writer; the runner and the health probe
#: only read.
GATE = QuotaGate()
