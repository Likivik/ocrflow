# 0002 — How the worker gets file bytes

**Status:** proposed — the code change waits on one decision (see Consequences).
**Context date:** 2026-10-27, Nextcloud 34, `worker/lib/nc_ocr_flow/webhook_server.py`
(3,798 lines) as it actually is, not as the plan imagined it.

## The problem the queue redesign did not solve

The queue protocol between the app and the worker is now well defined: the app
owns the job, the worker claims it with a shared secret, reports an outcome. What
that secret does *not* buy is access to the **bytes**. A claimed job is a path
string; fetching it needs WebDAV, and WebDAV needs a Nextcloud user.

Today that user is a single credential pair spread across the module:

- `NC_USER = os.environ.get("NC_OCR_NC_USER", "likivik")` — the default is the
  human account, so an unset variable silently runs the worker with that user's
  authority over the whole instance, not over the files it is told about;
- `NC_PASSWORD = _read_secret("NC_OCR_NC_PASSWORD")` — a bespoke env-or-`_FILE`
  helper, not the systemd `$CREDENTIALS_DIRECTORY` the new module wires up;
- **11** call sites pass `auth=(NC_USER, NC_PASSWORD)` directly, interleaved with
  OCR logic in a single module, and a further 38 lines touch the two globals.

So the worker's file access is: one over-privileged identity, one bespoke secret
path, and eleven places to get it wrong.

## Options

### A. Dedicated service account + app password (recommended)

A Nextcloud user `ocrflow-worker`, member of no groups beyond its own, given
access only to the folders OCR runs on, with a generated app password
(not the login password). Delivered via `LoadCredential` as
`$CREDENTIALS_DIRECTORY/ocrflow-dav-password`, which `nix/module.nix` already
declares.

- Cost: one `occ user:add`, one folder share or group, one `occ user:auth-token`
  equivalent per instance; ~15 minutes of runbook.
- Blast radius: the share, not the instance. A worker compromise cannot read
  `admin`'s files, settings or `occ`-equivalent surfaces.
- Failure mode when misconfigured: the DAV fetch 401s on the first claim and the
  job is reported `error` with a reason the queue already stores.
- Precedent in this codebase: the app's own secret already arrives via
  `LoadCredential`; this makes file access use the same mechanism instead of a
  second, weaker one.

### B. PHP-brokered per-job access

The app hands out a short-lived, single-file download URL when a job is claimed.

- Cost: new PHP endpoints, token table, expiry/replay handling, and a worker that
  no longer uses WebDAV at all — i.e. a rewrite of the 11 call sites, not a move.
- Benefit: the worker never holds standing credentials; revoking is instant.
- Real risk: a 40 MB scan now streams through PHP-FPM, and a slow worker holds a
  PHP worker open. It also puts file transfer on the same process pool that
  serves every page load.

### C. Keep one human credential (status quo)

Rejected: the default user is a person, the authority is instance-wide, and the
secret path bypasses the mechanism the rest of the deployment uses.

## Decision

**A**, unless there is a reason to distrust standing per-worker credentials that
A cannot answer. B is the better long-term shape and the worse one to build
before the queue has run in production for a week.

## Consequences / what the code change is

1. `NC_OCR_NC_USER` and `NC_OCR_NC_PASSWORD` are read once, in one place, at
   start-up: `$CREDENTIALS_DIRECTORY/ocrflow-dav-password`, username from
   `NC_OCR_DAV_USER`, and **fail closed** — an empty password refuses to start
   rather than issuing anonymous DAV requests that 401 on every file.
2. The 11 `auth=(NC_USER, NC_PASSWORD)` sites collapse into one `dav.py` helper
   with two functions: `download(path) -> bytes`, `put(path, body)`. No behaviour
   change — same retries, same timeouts, same paths.
3. The default `"likivik"` disappears. A missing username is a start-up error
   naming the option.
4. The runbook gains: create the account, grant the folders, mint the app
   password, write both files, `systemctl restart ocrflow-worker`.

Step 2 is deliberately last: moving 11 call sites in a 3,798-line module that
processes production documents buys nothing until (1) and (3) are settled, and it
breaks every test that monkeypatches `webhook_server.requests`.
