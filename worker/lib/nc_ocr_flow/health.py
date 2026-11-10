"""The worker's HTTP surface: just enough for probes and AppAPI.

AppAPI requires an ExApp to answer a Nextcloud -> ExApp connection check (it
records ``last_check_time``), and the lifecycle endpoints are ``/init`` and
``/enabled`` alongside ``/heartbeat``. The same server gives native deployments a
health check that systemd, Docker, or a monitoring probe can use.

``/heartbeat`` reads the store. A probe that only proves the process is alive is
not worth polling: this one reported ``ok`` while the index was unwritable, so a
wedged worker and a healthy worker looked identical to AppAPI and to a human.

Deliberately dependency-light: FastAPI is already a worker dependency, and the
module is importable without PyMuPDF/ONNX so tests stay fast.
"""

from __future__ import annotations

import threading
import time

from fastapi import FastAPI

from .config import env_str

_STARTED = time.monotonic()


def _store_state(probe=None) -> dict[str, object]:
    """Ask the store whether it is usable. Never raises: a probe reports."""
    try:
        state = (
            probe()
            if probe is not None
            else __import__("nc_ocr_flow.jobstore", fromlist=["health"]).health()
        )
    except Exception as exc:  # pragma: no cover - defensive
        return {"ready": False, "jobs_depth": None, "error": f"{type(exc).__name__}: {exc}"}
    return {
        "ready": bool(state.get("ready")),
        "jobs_depth": state.get("jobs_depth"),
        "error": state.get("error"),
    }


def create_app(
    worker_name: str | None = None,
    version: str | None = None,
    probe=None,
) -> FastAPI:
    """Build the probe app. Pure function: no side effects, easy to test."""
    name = worker_name or env_str("NC_OCR_WORKER_NAME", "worker")
    ver = version or env_str("NC_OCR_VERSION", "dev")

    app = FastAPI(
        title="ocrflow-worker",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )

    @app.get("/heartbeat")
    def heartbeat() -> dict[str, object]:
        """Liveness plus queue visibility. AppAPI polls this; monitoring can too.

        ``degraded`` means the process is up but cannot do its job: the index is
        missing, unwritable, or broken. ``jobs_depth`` is claimable plus running,
        so an operator sees a backlog without querying the database.
        """
        store = _store_state(probe)
        # Which engine this worker would use for a job of its own. On the api
        # transport the app decides per job, so ``null`` here is normal, not a
        # fault — the field exists so an operator can tell "decided per job"
        # from "unconfigured" without reading the source.
        engine = env_str("NC_OCR_ENGINE", "").strip().lower() or None
        return {
            "status": "ok" if store["ready"] else "degraded",
            "worker": name,
            "version": ver,
            "uptime_s": round(time.monotonic() - _STARTED, 1),
            "engine": engine,
            "store_ready": store["ready"],
            "jobs_depth": store["jobs_depth"],
            "error": store["error"],
        }

    @app.get("/init")
    def init_probe() -> dict[str, str]:
        """AppAPI lifecycle: initialisation probe."""
        return {"status": "ok"}

    @app.get("/enabled")
    def enabled_probe() -> dict[str, bool]:
        """AppAPI lifecycle: is the ExApp enabled?"""
        return {"enabled": True}

    @app.put("/enabled")
    def enabled_set() -> dict[str, str]:
        """AppAPI lifecycle: enable/disable notification.

        The worker holds no state worth toggling; refusing work is the queue's
        job. Acknowledging keeps the lifecycle contract honest.
        """
        return {"status": "ok"}

    return app


def start_health_server(
    port: int,
    host: str = "0.0.0.0",
    worker_name: str | None = None,
    version: str | None = None,
) -> threading.Thread:
    """Serve the probe app in a daemon thread and return that thread.

    A thread, not a process: the worker is a single-purpose loop and the probe
    must not outlive it. ``uvicorn.Server.serve()`` blocks, so it owns the
    thread it is given.
    """
    import uvicorn

    app = create_app(worker_name=worker_name, version=version)
    config = uvicorn.Config(app, host=host, port=port, log_level="warning", access_log=False)
    server = uvicorn.Server(config)

    thread = threading.Thread(target=server.run, name="ocrflow-health", daemon=True)
    thread.start()
    return thread


def health_port_from_env(env: dict[str, str] | None = None) -> int | None:
    """The port to listen on, or ``None`` to serve no probe at all.

    ``NC_OCR_HEALTH_PORT`` is ours: native deployments set it when they want a
    probe. ``APP_PORT`` is AppAPI's, and it is authoritative in ExApp mode —
    AppAPI picks the port and passes it in, so reading only our variable would
    make the container unreachable to the thing that deployed it.
    """
    import os as _os

    e = _os.environ if env is None else env
    for name in ("NC_OCR_HEALTH_PORT", "APP_PORT"):
        raw = (e.get(name) or "").strip()
        if not raw:
            continue
        try:
            port = int(raw)
        except ValueError:
            continue
        if 0 < port < 65536:
            return port
    return None
