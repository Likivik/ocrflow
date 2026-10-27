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
import sys
import time
from collections.abc import Callable
from pathlib import Path
from typing import Any

from .queue_client import Job, QueueClient

log = logging.getLogger("nc_ocr_flow.runner")

DEFAULT_IDLE_SECONDS = 5.0
CREDENTIAL_NAME = "ocrflow-worker-secret"


def read_secret(env: dict[str, str] | None = None) -> str:
    """Environment first, then systemd's credential directory.

    ``LoadCredential=`` is the trustworthy path: the secret never appears in
    ``systemctl show``, in the unit file, or in this process's environment as
    seen by other users.
    """
    env = env if env is not None else dict(os.environ)
    direct = env.get("NC_OCR_WORKER_SECRET", "").strip()
    if direct:
        return direct
    creds = env.get("CREDENTIALS_DIRECTORY", "").strip()
    if creds:
        path = Path(creds) / CREDENTIAL_NAME
        if path.is_file():
            return path.read_text(encoding="utf-8").strip()
    return ""


def _report(client: QueueClient, job: Job, outcome: dict[str, Any]) -> None:
    """Translate the engine's own vocabulary into the queue's, once."""
    if outcome.get("skipped"):
        # "Nothing to do" is not a failure: the file stays untouched and the row
        # records the reason, so an operator can tell "we decided not to" from
        # "we tried and broke".
        client.result(
            job.id,
            status="skipped",
            reason=outcome.get("reason") or "skipped",
        )
        return
    client.result(
        job.id,
        status="done",
        engine=outcome.get("engine"),
        pages=outcome.get("pages"),
        chars=outcome.get("chars"),
        stamp_version=outcome.get("stamp_version"),
    )


def run_once(
    client: QueueClient,
    process: Callable[..., dict[str, Any]],
    worker_name: str = "worker",
) -> bool:
    """Handle at most one job. Returns True when one was handled."""
    job = client.claim()
    if job is None:
        return False

    log.info("claimed job %s for %s (attempt %s)", job.id, job.path, job.attempts)
    try:
        outcome = process(job.path, job.file_id or 0)
    except Exception as exc:
        log.warning("job %s failed: %s", job.id, exc)
        client.failure(job.id, error=f"{type(exc).__name__}: {exc}", reason="worker_error")
        return True

    _report(client, job, outcome or {})
    return True


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(
        level=os.environ.get("NC_OCR_LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    base_url = os.environ.get("NC_OCR_URL", "").rstrip("/")
    if not base_url:
        log.error("NC_OCR_URL is not set; nothing to talk to")
        return 2
    secret = read_secret()
    if not secret:
        log.error(
            "no worker secret: set NC_OCR_WORKER_SECRET or provide %s/%s "
            "(see `occ ocrflow:worker:secret`)",
            os.environ.get("CREDENTIALS_DIRECTORY", "$CREDENTIALS_DIRECTORY"),
            CREDENTIAL_NAME,
        )
        return 2

    idle = float(os.environ.get("NC_OCR_IDLE_SECONDS", DEFAULT_IDLE_SECONDS))
    client = QueueClient(
        base_url=base_url,
        secret=secret,
        worker_name=os.environ.get("NC_OCR_WORKER_NAME", "worker"),
    )

    # Imported late so `--help`-ish paths and the unit tests do not need the
    # whole OCR stack (PyMuPDF, ONNX runtime, cloud SDKs) to be installed.
    #
    # Importing `webhook_server` starts its own scheduler unless told otherwise,
    # which would put the legacy webhook workers and the catch-up pass next to
    # this loop — two writers on one instance. This process owns the queue; the
    # old server does not run here.
    os.environ.setdefault("NC_OCR_RUN_SCHEDULER", "0")
    from .webhook_server import DavCredentialsMissing, _process_file, verify_dav_credentials

    # Before claiming anything: a worker with no WebDAV identity would claim a
    # job, fail to fetch it, and blame the document. Refuse to start instead.
    try:
        verify_dav_credentials()
    except DavCredentialsMissing as exc:
        log.error("%s", exc)
        return 2

    log.info("worker started: %s, idle %ss", base_url, idle)
    while True:
        try:
            handled = run_once(client, _process_file)
        except Exception as exc:
            log.error("worker loop error: %s", exc)
            time.sleep(min(idle * 4, 60))
            continue
        if not handled:
            time.sleep(idle)


if __name__ == "__main__":
    sys.exit(main())
