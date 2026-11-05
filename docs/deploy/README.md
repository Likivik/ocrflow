# Deploying OCR Flow

Three shapes, in order of how proven they are.

| Shape | Status | Worker credential |
|---|---|---|
| Native systemd (NixOS) | runs in production today | one app password (DAV) + the worker secret |
| Container, manual | verified: image starts, `/heartbeat` answers, api transport | the worker secret only |
| Container via AppAPI/HaRP | **starts and is probed, but queue calls are rejected** — see below | AppAPI's `APP_SECRET` |

Generate the worker secret once, on the Nextcloud host:

```bash
occ ocrflow:worker:secret       # prints it; put the value in sops or a .env file
```

Anyone holding that secret can claim jobs and read/write the files those jobs
refer to. Treat it as the worker's authority, because it is.

## 1. Native (no Docker)

See `nixos.nix`. Nextcloud on the host, the worker as a systemd unit, the secret
in a systemd credential (`LoadCredential=`, never in the environment).

This is the only shape that has carried real OCR traffic so far. It also still
uses the DAV transport, so the worker holds an app password for a single account:
files outside that account cannot be processed. The api transport removes that
dependency, but it has not yet been run in production.

## 2. Container, manual

`docker-compose.yml` is a reference layout. Start the worker against a running
Nextcloud:

```bash
docker run -d --name ocrflow-worker \
  -v ocrflow-state:/state \
  -e NC_OCR_TRANSPORT=api \
  -e NC_OCR_URL=https://cloud.example.com \
  -e NC_OCR_WORKER_NAME=manual-worker \
  -e NC_OCR_STATE_DIR=/state \
  -e NC_OCR_HEALTH_PORT=8080 \
  -e NC_OCR_WORKER_SECRET="$(cat /run/secrets/ocrflow-worker-secret)" \
  ghcr.io/likivik/ocrflow-worker:latest
```

Check it:

```bash
curl -s localhost:8080/heartbeat
# {"status":"ok","worker":"manual-worker","version":"...","uptime_s":1.2,
#  "store_ready":true,"jobs_depth":0,"error":null}
```

`status` is `degraded` when the worker cannot use its index — that is the case
worth alerting on. `jobs_depth` is claimable plus running.

In this shape the worker needs **no Nextcloud identity**: the app opens the file
as its owner and streams it back over `GET /ocs/v2.php/apps/ocrflow/api/v1/queue/{id}/file`,
and the result returns the same way.

## 3. Container via AppAPI + HaRP

AppAPI needs a Deploy Daemon. HaRP is the recommended one; verified facts:

- image `ghcr.io/nextcloud/nextcloud-appapi-harp:release`
- ports **8780** (ExApp HTTP), **8781** (HTTPS, only if `/certs/cert.pem` exists),
  **8782** (FRP tunnel the ExApps use)
- variables: `HP_SHARED_KEY` (required), `NC_INSTANCE_URL` (required, must be
  reachable *from* HaRP), `HP_EXAPPS_ADDRESS` (default `0.0.0.0:8780`),
  `HP_FRP_ADDRESS` (default `0.0.0.0:8782`), `HP_TRUSTED_PROXY_IPS`, `HP_LOG_LEVEL`
- volumes: `/var/run/docker.sock` (HaRP starts ExApp containers — write access to
  it is root on the host) and `/certs`

Run it, then register the daemon:

```bash
docker run -d --name appapi-harp -h appapi-harp \
  -e HP_SHARED_KEY="$(cat /run/secrets/harp-shared-key)" \
  -e NC_INSTANCE_URL="https://cloud.example.com" \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v "$PWD/certs:/certs" \
  -p 8780:8780 -p 8782:8782 \
  --restart unless-stopped \
  ghcr.io/nextcloud/nextcloud-appapi-harp:release

occ app_api:daemon:register harp \
  "HaRP" docker-install http appapi-harp:8780 \
  https://cloud.example.com --net host --set-default
```

Then `occ app_api:app:register ocrflow harp` (or the AppAPI admin page).

### What is missing before this shape processes a single file

AppAPI authenticates an ExApp with `AUTHORIZATION-APP-API: base64(userid:secret)`
and validates that header itself. These endpoints instead require the app's own
worker secret (`X-OcrFlow-Secret`, compared with `hash_equals`, failing closed
when unset). So an ExApp deployment today gives you a container that AppAPI can
start, update and probe — and whose `queue/next` calls the app answers with 401.

Closing that is a decision, recorded in the plan as R1: either the controller
accepts an AppAPI-authenticated caller as the worker (needs a test that a plain
user session is still rejected), or ExApp mode keeps using the worker secret from
an env var AppAPI does not manage. The first is recommended; the second puts a
secret where AppAPI prints configuration.

### Reverse proxy

HaRP must be reachable at `<nextcloud>/exapps/` from the instance's proxy. Example
for nginx:

```nginx
location /exapps/ {
    proxy_pass http://appapi-harp:8780;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## Note on the compose file

`docker-compose.yml` is a reference, not an installer: it has never been run as a
whole. What *is* verified is the worker image itself — built in CI on every PR,
then started with `NC_OCR_TRANSPORT=api`, no Nextcloud credentials, and required
to answer `/heartbeat` with `status: ok` while still alive after its start-up
gates run.
