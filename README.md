# ocrflow

OCR for Nextcloud: scans and images become searchable text **in place**, and no
upload is ever missed.

Two artefacts, one repository:

| Part | Language | What it owns |
| --- | --- | --- |
| **the app** (repo root) | PHP | the queue, per-file state, the settings, the routes, the `occ` command |
| **the worker** (`worker/`) | Python | everything expensive: download, classification, rasterising, OCR, text-layer embedding |

The split exists because PHP is the wrong runtime for a 40 MB scan turned into
200 page rasters, and Python is the wrong runtime for owning a Nextcloud
database. The worker is stateless: it claims a job, does it, reports the result.
Nothing durable lives in it, so it can be killed, restarted or replaced at any
moment without losing work.

## Why not a single ExApp

Considered and rejected. An ExApp can do the whole job — its Events Listener API
covers uploads (`node_event` → `NodeCreatedEvent`/`NodeWrittenEvent`) and its
TopMenu API covers the UI — but its state would live in a container volume:
invisible to `occ maintenance:backup`, gone with the container, and after a lost
volume every already-processed file looks new. In the database the same rows are
part of the instance, and `file_id` survives a rename or a move.

The worker is therefore **not** an ExApp and does not speak AppAPI: it is a
plain HTTP client of this app's own OCS endpoints.

## Layout

```
appinfo/     app manifest and routes
lib/         PHP: Db/Queue (the queue), Controller (worker and user planes),
             BackgroundJob (folder scan, stale-claim reconciler),
             Listener (upload events), Command, Migration (schema)
worker/      the Python worker and its test suite
```

## Where the truth lives

- **Database** (`ocrflow_jobs`, `ocrflow_files`) — the queue and per-file state:
  what is done, what failed and why, which engine produced the text.
- **XMP stamp inside the PDF** — provenance that travels with the file:
  `input_sha256`, engine and API version, page ranges, detected languages. Kept
  even though the database knows more, because a file copied out of Nextcloud and
  back should still say what was done to it.

## The two planes

- **Worker plane** — `/ocs/v2.php/apps/ocrflow/api/v1/queue/*`. Claim a job,
  report a result, report a failure. Authenticated by a shared secret
  (`occ ocrflow:worker:secret`) in the `X-OcrFlow-Secret` header, compared in
  constant time, never stored in the worker image. Every call also needs
  `OCS-APIRequest: true`.
- **User plane** — `/ocs/v2.php/apps/ocrflow/api/v1/{status,scan,file}`. Runs as
  the logged-in user, so Nextcloud's own permissions decide what can be scanned.

## Behaviour that must survive any rewrite

- **Once per version, not once per path.** A unit of work is `(file_id, etag)`:
  renaming or moving a file never re-runs OCR, edited content does, and a second
  delivery of the same event costs one indexed lookup. Enforced by a unique
  index in the app's own tables, not by a check (`docs/adr/0001`).
- **Never miss a file.** Nextcloud does not retry failed event deliveries
  (`WebhookCall` is a `QueuedJob`: non-2xx is logged and dropped, no backoff, no
  DLQ), so the queue is reconciled on a timer, not only on the event.
- **Never overwrite.** Collision names follow Nextcloud's own shape:
  `photo.pdf` → `photo(1).pdf`, `(n)` with no space, picked from one directory
  listing.
- **Reversible conversion.** An image becomes a PDF with the original JPEG
  embedded bit-for-bit (`img2pdf` is a DCTDecode passthrough); the worker can
  return the original with EXIF intact.
- **Fail closed.** If the classifier cannot decide, the job errors and the file
  is left untouched.

## Status

**Pre-release. Nothing here runs in production yet.**

- The PHP side (queue, controllers, listener, background jobs, migration) has no
  PHPUnit suite and has never been installed on a live Nextcloud.
- The worker still contains the previous prototype's webhook HTTP server: the
  queue client that claims jobs over OCS is **not written yet**, so the two
  halves do not talk to each other. Porting the panel from the worker's own
  `static/panel.html` into PHP settings is likewise still open.
- OCR logic, orientation, PDF classification and the extractor are ported from
  `Likivik/spectacle` (`pkgs/nc-ocr-flow`) with their test suite.

Production on `poweredge` still runs the old architecture: the `nc-ocr-flow`
systemd service plus the v0.8.0 PHP app from the `spectacle` flake. This
repository replaces both, and cutover has not happened.

## Licence

AGPL-3.0-or-later.
