"""The worker loop: claim one job, do it, report it. Repeat.

Statelessness is the point. Everything durable — what is done, what failed and
why — lives in the Nextcloud database on the other side of the queue. This
process can be killed at any instant: the job it was holding goes stale, the
app's reconciler puts it back, and another worker picks it up.

Config comes from the environment, because that is what systemd gives a service:
``NC_OCR_URL``, ``NC_OCR_WORKER_SECRET`` (or a file named in
``$CREDENTIALS_DIRECTORY/ocrflow-worker-secret``), ``NC_OCR_WORKER_NAME``,
``NC_OCR_IDLE_SECONDS``.
"""

from __future__ import annotations

import logging
import os
import signal
import sys
import threading
from collections.abc import Callable
from pathlib import Path
from typing import Any

from . import quota_gate
from . import slots as slots_mod
from .config import env_float, env_int, env_str
from .queue_client import ClaimStale, Job, QueueClient, QueueProtocolError
from .quota_gate import QuotaGate
from .readiness import report_configured_engine
from .stage_events import StageCollector, collector_scope, reason_code_for, verdict_code

# The slot supervisor, when this process is supervising forked slots. Kept
# module-level so the SIGTERM handler can ask the children to stop before the
# process exits; otherwise the signal kills the parent and the children are
# left to the cgroup, mid-job, holding claims nobody asked to release.
_SUPERVISOR: Any = None


# What the worker can tell the app about a failure. Deliberately small: every
# value here exists in the app's RetryPolicy table, and anything the worker does
# not recognise is ``unknown`` — bounded-retryable, never silently terminal.
def classify_failure(exc: BaseException) -> str:
    """Map a failure to one outcome code, by HTTP status when there is one.

    The status is the app's own answer and outranks text matching: without it a
    terminal 422 ("empty source, will never work") arrived as a
    QueueProtocolError and was classified app_5xx - retryable - the inversion
    this function exists to prevent. Exceptions with no status keep the
    text/name rules: transport corruption with no answer is still unknown work,
    and unknown must stay bounded-retryable.
    """
    # The app may have named the reason itself. An empty source is a fact
    # about the file, and "malformed request" told the operator to fix a
    # request that was fine — the same lie FileGone told.
    app_code = getattr(exc, "app_reason_code", None)
    if app_code == "error_source_file_zero_bytes":
        return "empty_source"

    status = getattr(exc, "status_code", None)
    if status is not None:
        if status in (401, 403):
            return "credentials_missing"
        if status == 409:
            # The app's 409 is not generic 4xx: it names our own code. The
            # claim is gone; retrying is wrong and so is calling it
            # app_4xx (which reads as "the document is the problem").
            return "error_worker_job_reference_stale"
        if 400 <= status < 500:
            return "app_4xx"
        if 500 <= status < 600:
            return "app_5xx"
    return _classify_by_text(exc)


def _classify_by_text(exc: BaseException) -> str:
    name = type(exc).__name__
    text = str(exc).lower()
    if "empty file" in text or "streamed an empty" in text:
        return "empty_stream"
    if name == "ImageTooLarge" or "too large to analyse" in text:
        return "image_too_large"
    if name == "ClassifierUnavailable" or "classifier unavailable" in text:
        # The ONNX model is missing or broken. The pipeline fails closed (no
        # conversion, no deletion), and the operator has to fix the model; the
        # old path landed this on `unknown`, which reads as "the worker sent a
        # code the app does not know" — the opposite of the truth.
        return "classifier_unavailable"
    if name == "FileGone":
        # The file was deleted or moved between queueing and reading. Nothing
        # about the worker's request was malformed, and no retry can help: the
        # app_4xx bucket told the operator to fix a request that was fine.
        return "file_gone"
    if "no such job" in text or "no longer exists" in text:
        return "app_4xx"
    if "credentials" in text or "defaultcredentials" in name.lower():
        return "credentials_missing"
    if name in ("Timeout", "TimeoutError", "RetryError", "ConnectTimeout", "ReadTimeout"):
        return "engine_timeout"
    if name in ("ConnectionError", "HTTPError", "RequestException"):
        return "app_5xx"
    if name == "QueueProtocolError":
        return "app_5xx"
    return "unknown"


log = logging.getLogger("nc_ocr_flow.runner")

DEFAULT_IDLE_SECONDS = 5.0
CREDENTIAL_NAME = "ocrflow-worker-secret"


def read_secret(env: dict[str, str] | None = None) -> str:
    """The worker secret, wherever this deployment keeps it.

    Order: our explicit variable, then AppAPI's ``APP_SECRET`` (generated by
    AppAPI and passed into the container — in ExApp mode it *is* the secret),
    then systemd's credential directory. ``LoadCredential=`` is the trustworthy
    native path: the value never appears in ``systemctl show``, in the unit file,
    or in this process's environment as seen by other users. The environment
    forms exist because a container has no credentials directory.
    """
    env = env if env is not None else dict(os.environ)
    for name in ("NC_OCR_WORKER_SECRET", "APP_SECRET"):
        direct = env.get(name, "").strip()
        if direct:
            return direct
    creds = env.get("CREDENTIALS_DIRECTORY", "").strip()
    if creds:
        path = Path(creds) / CREDENTIAL_NAME
        if path.is_file():
            return path.read_text(encoding="utf-8").strip()
    return ""


def _report(
    client: QueueClient,
    job: Job,
    outcome: dict[str, Any],
    stage_events: str | None = None,
) -> None:
    """Translate the engine's own vocabulary into the queue's, once."""
    if outcome.get("skipped"):
        # "Nothing to do" is not a failure: the file stays untouched and the row
        # records the reason, so an operator can tell "we decided not to" from
        # "we tried and broke".
        client.result(
            job.id,
            status="skipped",
            reason=outcome.get("reason") or "skipped",
            # A classifier verdict is a decision, and decisions are recorded
            # with a code. Without it the job row would say only "born digital"
            # in prose, and the table that answers "why" would say nothing.
            reason_code=verdict_code(outcome.get("reason")),
            # Provenance for the ours branch: the app's ledger row stores the
            # parsed engine and engine_version, and its engine bar decides
            # nothing_to_do vs redo from them.
            engine=outcome.get("engine"),
            engine_version=outcome.get("engine_version"),
            stamp_version=outcome.get("stamp_version"),
            stage_events=stage_events,
            claim=job.claim_token,
        )
        return
    client.result(
        job.id,
        status="done",
        engine=outcome.get("engine"),
        engine_version=outcome.get("engine_version"),
        pages=outcome.get("pages"),
        chars=outcome.get("chars"),
        stamp_version=outcome.get("stamp_version"),
        stage_events=stage_events,
        # Image→PDF conversion: the source image is not deleted by the
        # worker (a WebDAV DELETE is a permanent removal that never reaches
        # the trash bin). The app owns the file, so it moves the source to
        # the trash itself — it needs to be told which file and what the
        # conversion produced.
        source_converted="1" if outcome.get("source_converted") else None,
        source_path=outcome.get("source_path"),
        result_path=outcome.get("result_path"),
        claim=job.claim_token,
    )


def start_lease_renewal(
    client: QueueClient,
    job_id: int,
    claim: str | None,
    seconds: float = 30.0,
) -> threading.Event:
    """Keep a claim's lease alive while the job is being worked on.

    The lease, not the worker's heartbeat, is what recovery reads: a heartbeat
    proves the process lives, never that it still owns this job. Renewal is
    therefore per job, and it stops the moment the claim is gone.
    """
    stop = threading.Event()

    def _renew() -> None:
        while not stop.wait(seconds):
            try:
                if not client.renew(job_id, claim):
                    log.warning("job %s: the lease is gone; stopping renewal", job_id)
                    return
            except Exception as exc:  # a failed renewal is retried next tick
                log.warning("job %s: lease renewal failed: %s", job_id, exc)

    threading.Thread(target=_renew, name=f"ocrflow-lease-{job_id}", daemon=True).start()
    return stop


def run_once(
    client: QueueClient,
    process: Callable[..., dict[str, Any]],
    worker_name: str = "worker",
    gate: QuotaGate | None = None,
) -> bool:
    """Handle at most one job. Returns True when one was handled.

    ``gate`` is the vision-quota claim gate: when it blocks, this returns
    ``False`` without claiming, so a rate-limited quota cannot spend a job's
    attempts. The gate fails open on a missing or stale sample — see
    :mod:`nc_ocr_flow.quota_gate`.
    """
    if gate is not None and gate.check().blocked:
        return False
    job = client.claim()
    if job is None:
        return False

    lease = start_lease_renewal(client, job.id, job.claim_token)
    try:
        return _handle(client, job, process)
    finally:
        lease.set()


def _handle(client: QueueClient, job: Job, process: Callable[..., dict[str, Any]]) -> bool:
    log.info(
        "claimed job %s for %s (attempt %s, engine=%s)",
        job.id,
        job.path,
        job.attempts,
        job.engine,
    )
    # The collector is scoped around the whole job: whatever the pipeline
    # managed to do before it failed is still worth recording. A stage that
    # never ran is absent from the list, which is the point.
    collector = StageCollector()
    try:
        with collector_scope(collector):
            outcome = process(
                job.path,
                job.file_id or 0,
                engine=job.engine,
                job_id=job.id,
                claim_token=job.claim_token,
                # The app's "rescan anyway" rides the claim. Without passing
                # it on, a forced job would be skipped again by the exact
                # decision the operator was overriding.
                force=job.force,
            )
    except ClaimStale as exc:
        # 409: this claim is no longer ours — requeued or finished elsewhere
        # while we worked. Stop, do not retry, and do NOT report a failure:
        # the report would be refused with the same 409, and the job belongs
        # to whoever holds the claim now.
        log.warning(
            "job %s abandoned: the claim reference is stale (error_worker_job_reference_stale): %s",
            job.id,
            exc,
        )
        return True
    except Exception as exc:
        word = classify_failure(exc)
        # The wire carries the app's canonical name, never the classifier's
        # local word: `classify_failure` speaks `app_5xx`, the app's table
        # says `error_app_answered_5xx`. A word the table does not explain is
        # a worker bug, and reporting `error_worker_unclassified` is the one
        # honest answer that cannot be mistaken for a policy decision.
        code = reason_code_for(word) or "error_worker_unclassified"
        if code == "error_worker_unclassified" and word != "worker_error":
            log.error(
                "job %s: failure word %r has no canonical code; reporting "
                "error_worker_unclassified",
                job.id,
                word,
            )
        log.warning("job %s failed (%s): %s", job.id, code, exc)
        # The stage that blew up is the last one recorded; it gets the job's
        # failure code so the row says which stage failed, not just that
        # something did.
        collector.mark_last_reason(code)
        try:
            client.failure(
                job.id,
                error=f"{type(exc).__name__}: {exc}",
                reason="worker_error",
                reason_code=code,
                stage_events=collector.as_json(),
                claim=job.claim_token,
            )
        except ClaimStale as stale:
            log.warning("job %s: the claim is gone; nothing to release: %s", job.id, stale)
        except QueueProtocolError as report_exc:
            # The app never recorded this outcome. Waiting out the lease would
            # leave the slot idle on work nobody is doing, so hand the claim
            # back now — the row is queueable again on the next poll.
            log.error(
                "job %s: could not report the failure (%s); releasing the claim",
                job.id,
                report_exc,
            )
            client.release(job.id, job.claim_token)
        return True

    outcome = outcome or {}
    # A classifier verdict is a decision, and the stage that reached it is the
    # row that records it. The pipeline may already have marked the stage
    # (the classifier knows); this makes sure it is marked either way, so a
    # verdict can never reach the table as prose alone.
    if outcome.get("skipped"):
        verdict = verdict_code(outcome.get("reason"))
        if verdict is not None:
            collector.mark_last_reason(verdict)

    try:
        _report(client, job, outcome, collector.as_json())
    except ClaimStale as exc:
        # The same rule as mid-job: the claim went stale between our last
        # byte and this report. Abandon — the new holder owns the row, and
        # our stage events would land on their job.
        log.warning(
            "job %s abandoned at report time: the claim reference is stale: %s", job.id, exc
        )
    except QueueProtocolError as exc:
        # The app never recorded the outcome. Hand the claim back now rather
        # than making the row wait out its lease.
        log.error("job %s: could not report the result (%s); releasing the claim", job.id, exc)
        client.release(job.id, job.claim_token)
    return True


def _default_stop():
    """The scheduler stop, resolved late so importing runner stays cheap.

    Resolved through a function rather than a module-level import: runner is
    imported by tests that never load the OCR stack, and an import cycle at call
    time is the price of keeping start-up fast. The identity of this callable is
    asserted in tests, so the wiring cannot drift silently.
    """
    from .webhook_server import stop_scheduler

    return stop_scheduler


def start_heartbeat(client: QueueClient, seconds: int | float = 60) -> threading.Event:
    """Register the worker, then beat until the returned event is set.

    One daemon thread, one job: keep our registry row's `last_seen_at`
    fresh so the app's stale-claim recovery never mistakes us for dead.
    The heartbeat IS the evidence — a worker on a 30-minute PDF must not
    have its claim requeued from under it, which is what the old blind
    timer window did.

    Registration happens inline (the caller needs to know it happened
    before claiming); a failed registration is a warning, not a crash: an
    app older than the registry answers 404 and the worker keeps working
    the way it did before, its claims simply recover on the timer-less
    "unknown holder" rule.

    ``seconds`` comes from ``NC_OCR_HEARTBEAT_SECONDS`` (default 60); the
    app prunes rows ~3x that, so one lost beat kills nothing.
    """
    from . import __version__

    stop = threading.Event()
    try:
        client.register(__version__)
    except Exception as exc:
        log.warning("worker registration failed (%s); claims will carry no holder", exc)
        return stop

    def _beat() -> None:
        while not stop.wait(seconds if seconds > 0 else 1):
            try:
                if not client.heartbeat():
                    # Pruned while we ran (a long sleep, a suspended
                    # container): re-register and get a fresh row.
                    client.register(__version__)
            except Exception as exc:
                log.warning("heartbeat failed: %s", exc)

    threading.Thread(target=_beat, name="ocrflow-heartbeat", daemon=True).start()
    return stop


def _default_requeue():
    """Hand back jobs this process claimed. Late import, same reason."""
    from . import jobstore

    return jobstore.requeue_stale


def _engine_gate(transport: str) -> str | None:
    """Why the worker must not start, or ``None`` when it may.

    On the api transport the app stamps the engine on every job it queues, so the
    worker needs no opinion of its own — demanding one would refuse to start a
    correctly configured instance. On the dav transport the worker does pick, and
    an unconfigured worker used to default to `google`: it uploaded page images of
    user documents to a provider nobody chose. That is the defect this refuses.
    """
    if transport == "api":
        return None
    # Late import: the engine registry pulls in the OCR stack, and `runner` is
    # imported by things that only want read_secret().
    from .engines import EngineNotConfigured, resolve_engine

    try:
        resolve_engine()
    except EngineNotConfigured as exc:
        return str(exc)
    return None


def _graceful_shutdown(signum: int, *, stop=None, requeue=None) -> None:
    """Stop the loops and hand back the job this process was holding.

    Without this a SIGTERM killed the process mid-job: the row stayed 'running'
    until the *next* start requeued it, so an expensive cloud call could be made
    and billed twice. Bounded by design — a shutdown that waits on a slow network
    call is how a container gets killed anyway.

    Collaborators are parameters so the ordering and the failure isolation can be
    tested without a live scheduler: a test that has to patch module globals
    behind a running thread pool is testing the patch, not the shutdown.
    """
    supervisor = _SUPERVISOR
    if supervisor is not None:
        # First, before anything else waits: the children stop claiming, so
        # the queue does not grow a new in-flight job while we shut down.
        log.info("signal %s: asking %d slot(s) to stop", signum, len(supervisor.processes))
        supervisor.request_stop()
    log.info("signal %s: stopping the scheduler", signum)
    try:
        (stop or _default_stop())()
    except Exception:
        log.exception("shutdown: stop_scheduler failed")
    try:
        requeued = (requeue or _default_requeue())()
        if requeued:
            log.info("shutdown: requeued %d in-flight job(s)", requeued)
    except Exception:
        log.exception("shutdown: requeue failed")


def _install_signal_handlers() -> None:
    """SIGTERM/SIGINT -> :func:`_graceful_shutdown`, then exit 0.

    Installing handlers is only legal in the main thread, which is where this
    process runs; the guard keeps library use (tests, imports) quiet.
    """

    def _handler(signum: int, _frame) -> None:
        _graceful_shutdown(signum)
        raise SystemExit(0)

    try:
        for sig in (signal.SIGTERM, signal.SIGINT):
            signal.signal(sig, _handler)
    except ValueError:  # not the main thread
        log.debug("signal handlers not installed (not the main thread)")


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(
        level=env_str("NC_OCR_LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    # Name an unusable engine at boot instead of letting the first job
    # discover it: the host had tessdata but no ocrmypdf or tesseract
    # binary, and the module accepted the value regardless.
    report_configured_engine()

    base_url = env_str("NC_OCR_URL", "").rstrip("/")
    if not base_url:
        log.error("NC_OCR_URL is not set; nothing to talk to")
        return 2
    secret = read_secret()
    if not secret:
        log.error(
            "no worker secret: set NC_OCR_WORKER_SECRET or provide %s/%s "
            "(see `occ ocrflow:worker:secret`)",
            env_str("CREDENTIALS_DIRECTORY", "$CREDENTIALS_DIRECTORY"),
            CREDENTIAL_NAME,
        )
        return 2

    idle = env_float("NC_OCR_IDLE_SECONDS", DEFAULT_IDLE_SECONDS)
    worker_name = env_str("NC_OCR_WORKER_NAME", "worker")
    heartbeat = env_int("NC_OCR_HEARTBEAT_SECONDS", 60)
    slot_count = slots_mod.slots_from_env()

    client = QueueClient(base_url=base_url, secret=secret, worker_name=worker_name)

    # Register with the app's worker registry and keep the heartbeat going:
    # it is the evidence stale-claim recovery reads. Without it a claim of
    # ours would be requeued as soon as its holder looked unknown — which
    # is every claim, for a worker the registry has never heard of.
    #
    # Only the serial deployment registers here. With slots, each child
    # registers as `worker-N` and beats for itself: one shared row would be
    # kept fresh by a healthy sibling and would hide a dead slot's claims
    # from the stale-claim reconciler forever.
    if slot_count == 1:
        start_heartbeat(client, heartbeat)

    # Probe surface. Off unless NC_OCR_HEALTH_PORT is set, so native deployments
    # keep their current behaviour. Started before the checks below: a container
    # that is starting up must answer /heartbeat, otherwise AppAPI and Docker
    # both read it as dead.
    from .health import health_port_from_env, start_health_server

    health_port = health_port_from_env()
    if health_port is not None:
        start_health_server(health_port, worker_name=env_str("NC_OCR_WORKER_NAME", "worker"))
        log.info("health probe on :%d (/heartbeat)", health_port)

    _install_signal_handlers()

    # Imported late so `--help`-ish paths and the unit tests do not need the
    # whole OCR stack (PyMuPDF, ONNX runtime, cloud SDKs) to be installed.
    #
    # Importing `webhook_server` starts its own scheduler unless told otherwise,
    # which would put the legacy webhook workers and the catch-up pass next to
    # this loop — two writers on one instance. This process owns the queue; the
    # old server does not run here.
    os.environ.setdefault("NC_OCR_RUN_SCHEDULER", "0")
    from .webhook_server import (
        TRANSPORT,
        DavCredentialsMissing,
        _process_file,
        start_vision_quota_sampler,
        verify_dav_credentials,
    )

    # The claim gate needs fresh quota samples in the process that reads it.
    # On the serial path that is this one; with slots, every child samples for
    # itself. This process must NOT start one: forking a parent that holds a
    # live thread is how a child inherits a lock taken at fork time, and the
    # parent never claims a job, so its samples would be wasted API calls.

    # Before claiming anything: a worker with no way to reach files would claim a
    # job, fail to fetch it, and blame the document. Refuse to start instead.
    #
    # On NC_OCR_TRANSPORT=api there is nothing to verify: the app reads and writes
    # the file as its owner, and the worker only presents the shared secret — no
    # Nextcloud identity exists to be missing.
    # Same doctrine as the credential gate above: a worker with no engine would
    # claim a job, download the file and only then discover it cannot process it.
    # Refuse to start instead, and name the knob.
    engine_error = _engine_gate(TRANSPORT)
    if engine_error is not None:
        log.error("%s", engine_error)
        return 2
    log.info(
        "engine: %s",
        "decided per job by the app" if TRANSPORT == "api" else "from NC_OCR_ENGINE",
    )

    if TRANSPORT == "api":
        log.info("transport: api (no Nextcloud credentials needed)")
    else:
        try:
            verify_dav_credentials()
        except DavCredentialsMissing as exc:
            log.error("%s", exc)
            return 2

    # The slot number is an instruction, not a suggestion: nothing clamps it.
    # What the host can actually hold is logged next to it so a wrong number
    # is one visible line instead of a mystery OOM kill.
    log.info(
        "worker started: %s, idle %ss, slots=%d (host: %s cores, %s available)",
        base_url,
        idle,
        slot_count,
        os.cpu_count() or 0,
        _available_memory(),
    )
    if slot_count > 1:
        log.info(
            "slots forked as %s-1..%s-%d; each is its own registry row",
            worker_name,
            worker_name,
            slot_count,
        )

    if slot_count == 1:
        # One slot runs in this process: no fork, and a traceback in the log
        # still points at the process systemd started.
        start_vision_quota_sampler()
        slots_mod.slot_loop(
            client,
            _process_file,
            idle=idle,
            stop=threading.Event(),  # never set; SIGTERM exits instead
            gate=quota_gate.GATE,
        )
        return 0

    global _SUPERVISOR
    # The pool follows the app's `worker_slots` setting from here on: the
    # supervisor re-reads it while idle and resizes. Growth forks immediately;
    # shrinkage retires a slot after its current job, so nothing is redone.
    supervisor = slots_mod.SlotSupervisor(slots=slot_count, target_source=client.slots)
    _SUPERVISOR = supervisor
    supervisor.start(
        slots_mod.slot_main,
        base_url=base_url,
        secret=secret,
        worker_name=worker_name,
        idle=idle,
        heartbeat=heartbeat,
    )
    try:
        return supervisor.wait()
    except SystemExit:
        # The signal handler asked for a clean exit; the supervisor's join
        # has run in `wait`'s finally, so the children are already stopped.
        return 0


def _available_memory() -> str:
    """MemAvailable from /proc/meminfo, or '?' where there is no /proc."""
    try:
        with open("/proc/meminfo", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("MemAvailable:"):
                    return f"{int(line.split()[1]) // 1024} MB"
    except OSError:
        pass
    return "?"


if __name__ == "__main__":
    sys.exit(main())
