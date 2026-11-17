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

#: Every word this worker may put on the wire, and the canonical code the
#: app stores for it.
#:
#: The app owns the vocabulary of reason codes; the worker reports the code.
#: A code this table does not name is not sent at all (see
#: :data:`PROSE_ONLY_REASONS`), so the app never has to reconstruct a code
#: from an alias -- which it did for ``broken_pdf`` until 0.1.17: the row
#: said ``broken_pdf`` and ``jobs.reason_code`` was NULL.
#:
#: Keys are what the pipeline/classifier produce; values are ``RetryPolicy``
#: canonical names. Old spellings on the left are what a pre-0.1.5 worker
#: emitted: the app still aliases them on read, and an explicit entry here
#: means this worker maps them instead of relying on that read alias.
#: ``tests/Unit/Service/WorkerVocabularyTest.php`` refuses a value with no
#: canonical entry, and a key that no entry explains.
REASON_CODES: dict[str, str] = {
    # Classifier verdicts (2026-11-12 plan): decisions, not failures.
    "skip_text_native": "skip_text_native",
    "skip_ocred_by_ocrflow": "skip_ocred_by_ocrflow",
    "skip_ocred_by_suspected_foreign": "skip_ocred_by_suspected_foreign",
    "skip_classifier_says_photo": "skip_classifier_says_photo",
    "needs_ocr": "needs_ocr",
    # A damaged file: the classifier decided, the app's code says so.
    "broken_pdf": "error_file_broken",
    "error_file_broken": "error_file_broken",
    # The engine answered for every page and there was nothing to read.
    "no_text_detected": "skip_ocred_by_ocrflow_found_nothing",
    # Not a document we can read at all.
    "unsupported_ext": "skip_file_type_unsupported",
    # Failure words (runner.classify_failure), canonicalised at the wire.
    "empty_stream": "error_worker_got_empty_file",
    # The app refused the source before streaming anything: it is 0 bytes.
    "empty_source": "error_source_file_zero_bytes",
    "file_gone": "error_worker_file_gone",
    "classifier_unavailable": "error_classifier_unavailable",
    "credentials_missing": "error_provider_credentials_missing",
    "app_4xx": "error_worker_request_malformed",
    "app_5xx": "error_app_answered_5xx",
    "engine_timeout": "error_provider_timed_out",
    # The decoder refused the file: too many pixels to open at all.
    "image_too_large": "error_image_too_large",
    "worker_error": "error_worker_unclassified",
    "unknown": "error_worker_sent_unknown_code",
    "error_worker_job_reference_stale": "error_worker_job_reference_stale",
    # Legacy aliases — still accepted, never emitted by a current worker.
    "born_digital_text_based": "skip_text_native",
    "mobilenet": "skip_classifier_says_photo",
    "stamped_good_by_us": "skip_ocred_by_ocrflow",
}

#: Skip reasons that carry no app code at all: the app has no policy for
#: them and the free text is the whole answer. Kept explicit so the drift
#: guard can tell "deliberately prose" from "forgot the table".
PROSE_ONLY_REASONS: frozenset[str] = frozenset(
    {
        "skipped",  # runner._report's fallback for an unexplained skip
        "pdf_already_exists",  # image branch: the target PDF name is taken
        # fingerprint guard: the live file no longer matches what we left and the
        # foreign-rewrite cap is reached. A label for the operator, no app policy.
        "foreign_rewrite",
    }
)


def reason_code_for(reason: str | None) -> str | None:
    """The canonical app code for a reason the worker reports, or None.

    None means "this skip is prose only" (:data:`PROSE_ONLY_REASONS`), never
    "the table has no entry" -- an unmapped word is a bug, and the drift
    guard in ``tests/Unit/Service/WorkerVocabularyTest.php`` fails on it.
    """
    if reason is None:
        return None
    return REASON_CODES.get(reason)


#: The pre-0.1.17 name. Kept because the webhook's stage marking and the
#: worker tests speak it; it now resolves the whole table, not verdicts only.
verdict_code = reason_code_for


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
