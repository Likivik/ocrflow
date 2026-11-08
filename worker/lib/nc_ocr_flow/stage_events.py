"""Per-stage events: what each stage of a job did, and what it cost.

The worker is the only side that knows how long a stage took and how many
pages the engine was billed for. The app is the only side that decides what
may be stored. So the worker reports, and the app rules:

* the stage vocabulary is fixed -- seven names, from the architecture plan;
* a reason code belongs to the app's retry vocabulary, and the app records
  anything it does not know as ``unknown``;
* ``cost_pages`` belongs to the ``recognize`` stage alone, because that is
  the stage that spends money.

The events travel inside the finish payload the worker already sends, so a
job costs no extra HTTP call to be explained.
"""

from __future__ import annotations

import json
import logging
import time
from contextlib import contextmanager
from contextvars import ContextVar
from dataclasses import dataclass, field
from typing import Any

log = logging.getLogger(__name__)

#: The pipeline stages, in order. Fixed: a stage that never ran is absent
#: from the table, not folded into a neighbour.
STAGES: tuple[str, ...] = (
    "intake",
    "inspect",
    "classify",
    "prepare",
    "recognize",
    "compose",
    "deliver",
)

#: The only stage that can spend money.
COST_STAGE = "recognize"

#: The classifier's verdicts: decisions, not failures.
#:
#: The app owns the vocabulary of reason codes; the worker reports which
#: verdict it reached. These three mean "this file needs no work" -- the PDF
#: already carries a text layer, the image is a photo, our own earlier OCR
#: output is there. They are terminal: retrying cannot change the answer,
#: and re-OCR-ing a born-digital PDF costs money to learn the same thing.
#:
#: The free-text skip reasons the worker already produced are spelled the
#: same as the codes, so the mapping is the identity -- but it is written
#: down, so a divergence later is a one-line change and not a hunt.
VERDICT_CODES: dict[str, str] = {
    "born_digital_text_based": "born_digital_text_based",
    "mobilenet": "mobilenet",
    "stamped_good_by_us": "stamped_good_by_us",
}


def verdict_code(reason: str | None) -> str | None:
    """The reason code for a classifier verdict, or None for anything else.

    A skip that is not a verdict (``unsupported_ext``, ``foreign_rewrite``,
    ...) keeps its free-text reason and no code: the app has no policy for
    it, and inventing one here would only widen a vocabulary the app owns.
    """
    if reason is None:
        return None
    return VERDICT_CODES.get(reason)


_active: ContextVar[StageCollector | None] = ContextVar("ocrflow_stage_collector", default=None)


@dataclass(frozen=True)
class StageEvent:
    """One stage, one outcome."""

    stage: str
    reason_code: str | None = None
    duration_ms: int | None = None
    cost_pages: int | None = None

    def as_dict(self) -> dict[str, Any]:
        return {
            "stage": self.stage,
            "reason_code": self.reason_code,
            "duration_ms": self.duration_ms,
            "cost_pages": self.cost_pages,
        }


@dataclass
class StageCollector:
    """Collects the events of one job."""

    events: list[StageEvent] = field(default_factory=list)

    def record(
        self,
        stage: str,
        *,
        reason_code: str | None = None,
        duration_ms: int | None = None,
        cost_pages: int | None = None,
    ) -> StageEvent | None:
        """Record one stage. An unknown stage name is dropped with a warning."""
        if stage not in STAGES:
            log.warning("stage event for unknown stage %r is dropped", stage)
            return None
        if cost_pages is not None and stage != COST_STAGE:
            # Only the engine call is billed; a page count anywhere else is
            # a number nobody can act on.
            cost_pages = None
        event = StageEvent(
            stage=stage,
            reason_code=reason_code,
            duration_ms=duration_ms,
            cost_pages=cost_pages,
        )
        self.events.append(event)
        return event

    def mark_last_reason(self, reason_code: str) -> None:
        """Give the last recorded stage the job's failure code.

        The stage that blew up is the last one recorded, and the code comes
        from the app's vocabulary -- the worker classifies the failure once
        and the app owns the policy.
        """
        if not self.events:
            return
        last = self.events[-1]
        if last.reason_code is None:
            self.events[-1] = StageEvent(
                stage=last.stage,
                reason_code=reason_code,
                duration_ms=last.duration_ms,
                cost_pages=last.cost_pages,
            )

    def as_list(self) -> list[dict[str, Any]]:
        return [event.as_dict() for event in self.events]

    def as_json(self) -> str | None:
        """The JSON string the finish payload carries, or None when empty.

        None means "omit the key entirely": an empty list would look like a
        job that ran no stages at all, which is a different claim.
        """
        if not self.events:
            return None
        return json.dumps(self.as_list())


@contextmanager
def collector_scope(collector: StageCollector):
    """Make ``collector`` the one the pipeline code records into."""
    token = _active.set(collector)
    try:
        yield collector
    finally:
        _active.reset(token)


def current_collector() -> StageCollector | None:
    """The collector for the job being processed, if there is one."""
    return _active.get()


class StageTimer:
    """Times one stage and records it when the block ends.

    Used as a context manager::

        with StageTimer("recognize") as timer:
            result = process_pdf(...)
            timer.cost_pages = len(result.vlm_pages)

    The duration is recorded even when the block raises, because a stage
    that failed still took time.
    """

    def __init__(self, stage: str, collector: StageCollector | None = None) -> None:
        self.stage = stage
        self.collector = collector if collector is not None else current_collector()
        self.reason_code: str | None = None
        self.cost_pages: int | None = None
        self.duration_ms: int | None = None
        self._start = 0.0

    def __enter__(self) -> StageTimer:
        self._start = time.monotonic()
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        self.duration_ms = int((time.monotonic() - self._start) * 1000)
        if self.collector is not None:
            self.collector.record(
                self.stage,
                reason_code=self.reason_code,
                duration_ms=self.duration_ms,
                cost_pages=self.cost_pages,
            )
