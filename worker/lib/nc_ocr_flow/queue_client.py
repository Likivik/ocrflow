"""The worker's only channel to Nextcloud: this app's own OCS queue endpoints.

The worker is not an ExApp and holds no Nextcloud credentials for this: it
presents a shared secret over OCS. Everything it needs to know about a job comes
back in the claim response, so the worker can be killed between any two calls
without corrupting anything.

Two details cost an afternoon each if missed, so they are handled here once:

* every call needs ``OCS-APIRequest: true`` — without it OCS answers with the
  web UI's HTML and the JSON parse fails with a message that says nothing;
* OCS wraps payloads in ``<ocs><data>`` (or ``{"ocs": {"data": ...}}``), and an
  HTTP 200 can still carry a failure status. A body is only a success if
  ``ocs.meta.statuscode`` is 200 and ``ocs.data`` is present.
"""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import requests

log = logging.getLogger(__name__)

SECRET_HEADER = "X-OcrFlow-Secret"
WORKER_HEADER = "X-OcrFlow-Worker"
API_PATH = "/ocs/v2.php/apps/ocrflow/api/v1"


class WorkerUnauthorized(RuntimeError):
    """The secret was rejected. Retrying cannot fix this; a human must."""


class QueueProtocolError(RuntimeError):
    """The server answered, but not with something we understand."""


class FileGone(QueueProtocolError):
    """The app says the file behind this job no longer exists.

    Distinct from a transport failure: retrying cannot help, the job should be
    closed with its reason recorded rather than burning attempts.
    """


@dataclass(frozen=True)
class Job:
    """One unit of work, exactly as the app describes it."""

    id: int
    path: str
    file_id: int | None
    etag: str | None
    size: int | None
    attempts: int

    @classmethod
    def from_api(cls, data: dict[str, Any]) -> Job:
        # Indexed, not `.get()`: a missing identity field must fail loudly. The
        # first version used `.get('file_id')` and silently returned None when
        # the app emitted `fileId` instead — the queue's uniqueness key, quietly
        # dropped.
        try:
            return cls(
                id=int(data["id"]),
                path=str(data["path"]),
                file_id=None if data["file_id"] is None else int(data["file_id"]),
                etag=None if data["etag"] is None else str(data["etag"]),
                size=None if data.get("size") is None else int(data["size"]),
                attempts=int(data.get("attempts") or 0),
            )
        except (KeyError, TypeError, ValueError) as exc:
            raise QueueProtocolError(f"claim returned a job we cannot read: {data!r}") from exc


class QueueClient:
    def __init__(
        self,
        base_url: str,
        secret: str,
        worker_name: str = "worker",
        timeout: float = 30.0,
        session: requests.Session | None = None,
    ) -> None:
        if not secret:
            raise ValueError("worker secret is empty: the app would reject every call")
        self.base_url = base_url.rstrip("/")
        self.secret = secret
        self.worker_name = worker_name[:64]
        self.timeout = timeout
        self.session = session or requests.Session()

    # -- worker plane ----------------------------------------------------

    def claim(self) -> Job | None:
        """Ask for one job. ``None`` means the queue is empty, not that we failed."""
        response = self._post("queue/next")
        if response.status_code == 204:
            return None
        return Job.from_api(self._data(response))

    def result(self, job_id: int, **fields: Any) -> None:
        """Report success. Only the keys the app documents are sent."""
        payload = {k: v for k, v in fields.items() if v is not None}
        self._post(f"queue/{job_id}/result", data=payload)

    def failure(self, job_id: int, error: str, reason: str | None = None) -> None:
        self._post(
            f"queue/{job_id}/failure",
            data={"error": error[:500], "reason": reason or ""},
        )

    def fetch_file(self, job_id: int, dest: Path) -> Path:
        """Download a job's bytes into ``dest``.

        This is what replaces the WebDAV download. The app opens the file as its
        owner and streams it back over this same shared-secret channel, so the
        worker needs no Nextcloud identity at all — and a container cannot read
        anything the queue did not hand it.

        A 404 here is a real answer, not a transport error: the file was moved or
        deleted after it was queued, and the caller should close the job.
        """
        response = self.session.get(
            f"{self.base_url}{API_PATH}/queue/{job_id}/file",
            headers=self._headers(),
            timeout=self.timeout,
            stream=True,
        )
        if response.status_code in (401, 403):
            raise WorkerUnauthorized(
                f"the app rejected the worker secret ({response.status_code}) on the file endpoint"
            )
        if response.status_code == 404:
            raise FileGone(f"job {job_id}: the app reports the file no longer exists")
        if response.status_code >= 500:
            raise QueueProtocolError(f"server error {response.status_code} fetching job {job_id}")
        if response.status_code != 200:
            raise QueueProtocolError(
                f"unexpected {response.status_code} fetching job {job_id}"
            )

        dest.parent.mkdir(parents=True, exist_ok=True)
        written = 0
        with open(dest, "wb") as handle:
            for chunk in response.iter_content(chunk_size=256 * 1024):
                if chunk:
                    handle.write(chunk)
                    written += len(chunk)
        if written == 0:
            # An empty body would be OCR'd as an empty document and reported as
            # success — worse than an error, because the file would be skipped
            # forever. Refuse it here.
            dest.unlink(missing_ok=True)
            raise QueueProtocolError(f"job {job_id}: the app streamed an empty file")
        return dest

    def _headers(self) -> dict[str, str]:
        return {
            "OCS-APIRequest": "true",
            "Accept": "application/json",
            SECRET_HEADER: self.secret,
            WORKER_HEADER: self.worker_name,
        }

    # -- plumbing --------------------------------------------------------

    def _post(self, path: str, data: dict[str, Any] | None = None) -> requests.Response:
        response = self.session.post(
            f"{self.base_url}{API_PATH}/{path}",
            data=data or {},
            headers={
                "OCS-APIRequest": "true",
                "Accept": "application/json",
                SECRET_HEADER: self.secret,
                WORKER_HEADER: self.worker_name,
            },
            timeout=self.timeout,
        )
        if response.status_code in (401, 403):
            raise WorkerUnauthorized(
                f"the app rejected the worker secret ({response.status_code}); "
                "rotate with `occ ocrflow:worker:secret` and update the worker, "
                "then reload php-fpm"
            )
        if response.status_code >= 500:
            raise QueueProtocolError(f"server error {response.status_code} on {path}")
        return response

    @staticmethod
    def _data(response: requests.Response) -> dict[str, Any]:
        try:
            body = response.json()
        except json.JSONDecodeError as exc:
            raise QueueProtocolError(
                f"{response.request.method} {response.request.path_url} returned "
                f"{response.status_code} without JSON — is OCS-APIRequest being sent?"
            ) from exc

        ocs = body.get("ocs") if isinstance(body, dict) else None
        if not isinstance(ocs, dict):
            raise QueueProtocolError(f"not an OCS envelope: {str(body)[:200]}")
        meta = ocs.get("meta") or {}
        status = int(meta.get("statuscode") or response.status_code)
        if status != 200:
            raise QueueProtocolError(
                f"OCS refused {response.request.path_url}: {status} {meta.get('message')!r}"
            )
        data = ocs.get("data")
        if not isinstance(data, dict):
            raise QueueProtocolError(f"OCS envelope without data: {str(body)[:200]}")
        return data
