# Security policy

## Reporting a vulnerability

Report privately through GitHub: **Security → Advisories → Report a vulnerability**
(`https://github.com/Likivik/ocrflow/security/advisories/new`).

Do not open a public issue or PR for a security problem.

Include what you can: affected version or commit, the request/input that
triggers it, and what an attacker gains. We will confirm receipt, say whether we
agree it is a vulnerability, and coordinate a disclosure date.

## Supported versions

The latest release only. This app is pre-1.0 — fixes land on `main` and in the
next release; there are no maintenance branches.

## Scope worth knowing

- This app **sends document images to a third-party OCR service** (Google Cloud
  Vision by default, or a configured alternative). That data flow is the app's
  core function and is documented in the README; report it as a vulnerability
  only if it happens without the admin opting in, or to a destination other than
  the configured one.
- Credentials (Nextcloud app password, webhook/API secrets, cloud keys) are read
  from the environment or the container secret store. A code path that logs,
  returns, or persists a secret is in scope.
- Anything that lets one Nextcloud user read another user's file content,
  extracted text, or job history is in scope.

## What we do in the repository

- Secret scanning and push protection on the repository.
- CodeQL on push and on a schedule.
- Dependabot for actions, Python dependencies and the container base image.
- `ruff`, `mypy` and the full test suite as required checks before merge.
