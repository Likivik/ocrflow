# ocrflow

OCR for Nextcloud: images and PDFs become searchable text **in place**, and no
upload is ever missed.

Shipped as a single **ExApp** (Nextcloud AppAPI) — one Docker image, one App
Store entry, backend + UI together. No PHP app, no external systemd unit.

## Why an ExApp

Verified against the Nextcloud 35 developer manual (2026-10):

| Need | ExApp mechanism |
| --- | --- |
| React to uploads | `POST /apps/app_api/api/v1/events_listener` — `eventType: node_event`, subtypes `NodeCreatedEvent`, `NodeWrittenEvent`, `NodeRenamedEvent`. Explicitly asynchronous ("more like a notification system"), so the catch-up pass for missed events still matters. |
| Admin panel + buttons | `POST /apps/app_api/api/v1/ui/top-menu`, `ui/script`, `ui/style`, `ui/initial-state` — AppAPI proxies to a Vue app served from the container. |
| File reads/writes | AppAPI credentials + WebDAV/OCS as an app-scoped client. |
| Lifecycle | `GET /heartbeat`, `POST /init`, `POST /enabled`. |

Cost: the host needs AppAPI plus a Deploy Daemon (HaRP recommended, Docker).

## Behaviour that carries over from the prototype

- **Never miss a file.** Nextcloud does not retry failed event deliveries
  (`WebhookCall` is a `QueuedJob`: non-2xx is logged and dropped, no backoff, no
  DLQ), so a watermark-based catch-up pass runs on start and on a timer.
- **Reversible conversion.** An image becomes a PDF with the original JPEG
  embedded bit-for-bit (`img2pdf` is a DCTDecode passthrough); `ocrflow-extract`
  pulls the original back out with EXIF intact.
- **Fail closed.** If the classifier cannot load, nothing is converted or deleted.
- **Never overwrite.** Collision names follow Nextcloud's own `getUniqueName`
  shape: `photo.pdf` → `photo(1).pdf`, `(n)` with no space, from one directory
  listing.

## Status

Skeleton stage. The working prototype (engine, catch-up, extractor, tests) lives
in `Likivik/spectacle` under `pkgs/nc-ocr-flow` and is being ported here.

## Licence

AGPL-3.0-or-later — required for App Store publication.
