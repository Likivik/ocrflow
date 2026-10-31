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
