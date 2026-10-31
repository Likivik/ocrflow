"""The worker's HTTP surface: just enough for probes and AppAPI.

Nothing here touches the queue. AppAPI requires an ExApp to answer a
Nextcloud -> ExApp connection check (it records ``last_check_time``), and the
lifecycle endpoints are ``/init`` and ``/enabled`` alongside ``/heartbeat``.
The same server gives native deployments a health check that systemd,
Docker, or a monitoring probe can use.

Deliberately dependency-light: FastAPI is already a worker dependency, and the
module is importable without PyMuPDF/ONNX so tests stay fast.
"""

from __future__ import annotations

import os
import threading
import time

from fastapi import FastAPI

_STARTED = time.monotonic()


def create_app(worker_name: str | None = None, version: str | None = None) -> FastAPI:
    """Build the probe app. Pure function: no side effects, easy to test."""
    name = worker_name or os.environ.get("NC_OCR_WORKER_NAME", "worker")
    ver = version or os.environ.get("NC_OCR_VERSION", "dev")

    app = FastAPI(
        title="ocrflow-worker",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )

    @app.get("/heartbeat")
    def heartbeat() -> dict[str, object]:
        """Liveness. AppAPI polls this; monitoring can too."""
        return {
            "status": "ok",
            "worker": name,
            "version": ver,
            "uptime_s": round(time.monotonic() - _STARTED, 1),
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
    """``NC_OCR_HEALTH_PORT`` or nothing — unset means "no HTTP server"."""
    env = env if env is not None else dict(os.environ)
    raw = (env.get("NC_OCR_HEALTH_PORT") or "").strip()
    if not raw:
        return None
    try:
        port = int(raw)
    except ValueError:
        return None
    if not 0 < port < 65536:
        return None
    return port
