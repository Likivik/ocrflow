"""The probe surface: /heartbeat, /init, /enabled.

AppAPI does a Nextcloud -> ExApp connection check and records `last_check_time`
per ExApp, so an ExApp that answers nothing would look permanently broken. The
same endpoints give a plain Docker deployment a health check.

These are unit tests on the ASGI app: no ports, no threads, no Nextcloud.
"""

from __future__ import annotations

from fastapi.testclient import TestClient

from nc_ocr_flow.health import create_app, health_port_from_env


def _client() -> TestClient:
    return TestClient(create_app(worker_name="w1", version="9.9.9"))


def test_heartbeat_reports_identity() -> None:
    with _client() as client:
        response = client.get("/heartbeat")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["worker"] == "w1"
    assert body["version"] == "9.9.9"
    assert body["uptime_s"] >= 0


def test_lifecycle_endpoints_answer() -> None:
    with _client() as client:
        assert client.get("/init").json() == {"status": "ok"}
        assert client.get("/enabled").json() == {"enabled": True}
        assert client.put("/enabled").json() == {"status": "ok"}


def test_unknown_path_is_404() -> None:
    with _client() as client:
        assert client.get("/ocr").status_code == 404


def test_health_port_parsing(monkeypatch) -> None:
    assert health_port_from_env({"NC_OCR_HEALTH_PORT": "8080"}) == 8080
    assert health_port_from_env({}) is None
    assert health_port_from_env({"NC_OCR_HEALTH_PORT": "  "}) is None
    assert health_port_from_env({"NC_OCR_HEALTH_PORT": "nope"}) is None
    assert health_port_from_env({"NC_OCR_HEALTH_PORT": "0"}) is None
    assert health_port_from_env({"NC_OCR_HEALTH_PORT": "70000"}) is None


# --- the probe must see the store -------------------------------------------


def test_heartbeat_reports_the_store_when_it_is_usable() -> None:
    app = create_app(probe=lambda: {"ready": True, "jobs_depth": 4, "error": None})
    with TestClient(app) as client:
        body = client.get("/heartbeat").json()

    assert body["status"] == "ok"
    assert body["store_ready"] is True
    assert body["jobs_depth"] == 4


def test_heartbeat_degrades_when_the_store_cannot_answer() -> None:
    """The whole point: a wedged worker must not look healthy.

    Before this, /heartbeat answered `ok` with an unwritable index, so AppAPI's
    last_check_time (and any operator dashboard) reported a healthy worker that
    could not process a single job.
    """
    app = create_app(
        probe=lambda: {
            "ready": False,
            "jobs_depth": None,
            "error": "index not usable at /state/jobs.db",
        }
    )
    with TestClient(app) as client:
        body = client.get("/heartbeat").json()

    assert body["status"] == "degraded"
    assert body["store_ready"] is False
    assert "index not usable" in (body["error"] or "")


def test_heartbeat_survives_a_probe_that_raises() -> None:
    def _boom():
        raise RuntimeError("database is locked")

    app = create_app(probe=_boom)
    with TestClient(app) as client:
        body = client.get("/heartbeat").json()

    assert body["status"] == "degraded"
    assert "database is locked" in (body["error"] or "")


def test_default_probe_reads_the_real_index() -> None:
    """No injection: the default path asks jobstore.health(), which is the code
    the container runs."""
    app = create_app()
    with TestClient(app) as client:
        body = client.get("/heartbeat").json()

    assert body["status"] == "ok", body
    assert isinstance(body["jobs_depth"], int)


def test_appapi_port_is_used_when_ours_is_absent() -> None:
    """AppAPI picks the port and passes APP_PORT; ignoring it makes the container
    unreachable to the only thing that deployed it."""
    assert health_port_from_env({"APP_PORT": "8123"}) == 8123
    # ours wins when both exist: a native operator who set it meant it
    assert health_port_from_env({"NC_OCR_HEALTH_PORT": "8080", "APP_PORT": "8123"}) == 8080
