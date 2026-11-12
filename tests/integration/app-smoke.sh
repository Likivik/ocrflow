#!/usr/bin/env bash
# Integration smoke for the PHP side of ocrflow, against a real Nextcloud.
#
# The unit suite proves the schema object and the secret gate in isolation.
# This proves the things only a running instance can answer: does the migration
# land on the real database engine, does the app enable, does an upload reach the
# listener, does a claim hand out a job over OCS, and does a second claim answer
# "nothing to do".
#
# Requires: podman (or docker) and network access to pull one image.
# Exits non-zero on the first failed assertion, printing the failing command.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# Pinned by digest, on purpose. The app declares `php max-version 8.4` and a
# `pgsql`-only database dependency (appinfo/info.xml), and the install-time
# dependency check enforces both. The floating `34-apache` tag now ships PHP
# 8.5 and the smoke used to install on SQLite — outside the declared envelope
# on both counts, so `occ app:enable` correctly refused and the whole job
# failed on the metadata instead of the app. 34.0.0-apache is the last 34.x
# image built on PHP 8.4 (34.0.1+ are PHP 8.5), so it is what CI can run the
# app on; the digest makes the PHP version part of the test, not of the
# registry's rebuild schedule.
IMAGE="${NC_IMAGE:-docker.io/library/nextcloud@sha256:5baecc7baf8991ca0d4b202c349c75c1c83129568284fd1ec6bb7e4417e0a5bb}"
PG_IMAGE="${PG_IMAGE:-docker.io/library/postgres@sha256:3540c0362c809df084ffac0ae69fe794515f9de92692f02178bcdce0ef0302ac}"
NAME="ocrflow-it-$$"
PGNAME="ocrflow-it-pg-$$"
NET="ocrflow-it-net-$$"
PORT="${NC_PORT:-8089}"
ADMIN_USER="admin"
ADMIN_PASS="integration-pass-123"
BASE="http://127.0.0.1:${PORT}"
PG_DB="nextcloud"
PG_USER="ocrflow"
PG_PASS="integration-pg-pass-123"
# Cold runners with a loaded disk took over 120s to install the image; the fix
# for a slow install is a longer budget, not a rerun.
INSTALL_TIMEOUT_S="${INSTALL_TIMEOUT_S:-420}"
ENABLE_TIMEOUT_S="${ENABLE_TIMEOUT_S:-180}"

# Nextcloud prefixes app tables with the configured dbtableprefix, `oc_` by
# default. Querying `ocrflow_jobs` yields "no such table", which looks exactly
# like a migration that never ran — that trap cost one full debugging round.
JOBS="oc_ocrflow_jobs"
FILES="oc_ocrflow_files"
SETTINGS="oc_ocrflow_settings"

ENGINE=""
if command -v podman >/dev/null 2>&1; then ENGINE=podman; elif command -v docker >/dev/null 2>&1; then ENGINE=docker; fi
[ -n "$ENGINE" ] || { echo "FAIL: no container engine"; exit 1; }

fail() {
  echo "FAIL: $*"
  echo "--- nextcloud.log, last 3 lines mentioning ocrflow ---"
  $ENGINE exec "$NAME" sh -c 'grep -i ocrflow /var/www/html/data/nextcloud.log 2>/dev/null | tail -3' 2>/dev/null | cut -c1-900 || true
  echo "--- last 20 lines of the container log ---"
  $ENGINE logs --tail 20 "$NAME" 2>&1 || true
  exit 1
}
ok() { echo "ok: $*"; }

# Enabling an app restarts apache, and a request that lands in that window dies
# with curl error 56 — which reads like an app failure and is not one. Retry a
# transport error; never retry an HTTP status (curl exits 0 for 4xx, so a real
# 401 still fails the assertion immediately).
curl() {
  local i out
  for i in 1 2 3 4 5; do
    # Buffer per attempt: a failed attempt still writes its -w output ("000"),
    # and printing both would turn a retried 401 into "000401".
    if out=$(command curl "$@"); then
      printf '%s' "$out"
      return 0
    fi
    sleep 2
  done
  return 1
}

cleanup() {
  $ENGINE rm -f "$NAME" >/dev/null 2>&1 || true
  $ENGINE rm -f "$PGNAME" >/dev/null 2>&1 || true
  $ENGINE network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

occ() { $ENGINE exec -u www-data "$NAME" php occ "$@" 2>&1; }

# Query the instance's Postgres from php inside the Nextcloud container, so no
# host psql client is needed. The connection settings are the same env vars
# the entrypoint installed the instance with.
sql() {
  $ENGINE exec "$NAME" php -r '
$p = new PDO(sprintf("pgsql:host=%s;dbname=%s", $_SERVER["POSTGRES_HOST"], $_SERVER["POSTGRES_DB"]),
  $_SERVER["POSTGRES_USER"], $_SERVER["POSTGRES_PASSWORD"]);
foreach ($p->query($argv[1])->fetchAll(PDO::FETCH_NUM) as $row) { echo implode("|", $row), PHP_EOL; }
' "$1"
}

echo "== starting $PG_IMAGE as $PGNAME"
$ENGINE network create "$NET" >/dev/null
$ENGINE run -d --name "$PGNAME" --network "$NET" \
  -e POSTGRES_DB="$PG_DB" \
  -e POSTGRES_USER="$PG_USER" \
  -e POSTGRES_PASSWORD="$PG_PASS" \
  "$PG_IMAGE" >/dev/null

echo "== starting $IMAGE as $NAME"
$ENGINE run -d --name "$NAME" --network "$NET" -p "127.0.0.1:${PORT}:80" \
  -e POSTGRES_HOST="$PGNAME" \
  -e POSTGRES_DB="$PG_DB" \
  -e POSTGRES_USER="$PG_USER" \
  -e POSTGRES_PASSWORD="$PG_PASS" \
  -e NEXTCLOUD_ADMIN_USER="$ADMIN_USER" \
  -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
  -e NEXTCLOUD_TRUSTED_DOMAINS="127.0.0.1 localhost" \
  "$IMAGE" >/dev/null

echo "== waiting for the instance to install"
# The old loop was a fixed 60 x 2s = 120s and then one last check, so a slow
# runner produced "FAIL: instance never finished installing" with no evidence
# and no way to tell a slow install from a broken one. The budget is now a
# deadline, and expiring it prints what the container actually said.
install_deadline=$(( $(date +%s) + INSTALL_TIMEOUT_S ))
until $ENGINE exec -u www-data "$NAME" php occ status 2>/dev/null | grep -q 'installed: true'; do
  if [ "$(date +%s)" -ge "$install_deadline" ]; then
    echo "-- occ status said:"
    $ENGINE exec -u www-data "$NAME" php occ status 2>&1 | head -12 || true
    fail "instance never finished installing within ${INSTALL_TIMEOUT_S}s"
  fi
  sleep 3
done
ok "instance installed"

echo "== copying the app in"
$ENGINE exec "$NAME" mkdir -p /var/www/html/custom_apps/ocrflow
# Everything the app needs at run time, not just the PHP: the committed Vue
# bundle in js/ and the icon in img/ are part of the app, not build artefacts of
# the test.
for part in appinfo lib templates js img l10n; do
  if [ -d "${APP_DIR}/${part}" ]; then
    $ENGINE cp "${APP_DIR}/${part}" "$NAME:/var/www/html/custom_apps/ocrflow/${part}"
  fi
done

# `occ app:enable` is not the first thing to touch the database: on a cold runner
# the entrypoint is still finishing its own install, and enabling exactly once
# hid the reason — the run failed with "app not in app:list", which names the
# symptom and nothing else. Retry to a deadline and keep the output.
echo "== enabling the app"
enable_deadline=$(( $(date +%s) + ENABLE_TIMEOUT_S ))
until occ app:enable ocrflow >/tmp/ocrflow-enable.log 2>&1 \
      && occ app:list 2>/dev/null | grep -q ocrflow; do
  if [ "$(date +%s)" -ge "$enable_deadline" ]; then
    echo "-- occ app:enable said:"; tail -6 /tmp/ocrflow-enable.log || true
    echo "-- occ app:list said:"; occ app:list 2>&1 | head -8 || true
    fail "app could not be enabled within ${ENABLE_TIMEOUT_S}s"
  fi
  sleep 3
done

# The engine is instance policy and has no default: until one is chosen the
# listener queues nothing, which is correct and would make every upload test fail
# for the wrong reason. Choose the offline engine, as the deployment docs do.
occ ocrflow:engine tesseract >/tmp/ocrflow-engine.log 2>&1 || {
    echo "FAIL: could not set the OCR engine"; cat /tmp/ocrflow-engine.log; exit 1; }
occ ocrflow:engine | tee /tmp/ocrflow-engine-now.log
grep -q 'engine: tesseract' /tmp/ocrflow-engine-now.log || {
    echo "FAIL: engine did not stick"; exit 1; }
ok "app enabled"

# Nextcloud 34 has no `occ app:check-code`: the app checker was removed from
# core. An earlier version of this smoke called it anyway, and the assertion
# passed because the failure output did not match the pattern it grepped for —
# a check that cannot fail is not a check. The replacement is PHPStan in the
# php job; what this smoke can still prove is that the shipped app carries its
# translations, which the copy list above used to drop.
if ! $ENGINE exec "$NAME" test -f /var/www/html/custom_apps/ocrflow/l10n/ru.json; then
    fail "the app in the container has no l10n/ru.json"
fi
ok "the app ships its translations"

echo "== the panel renders inside personal settings"
code=$(curl -s -o /tmp/ocrflow-panel.html -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  "$BASE/settings/user/ocrflow")
[ "$code" = "200" ] || fail "settings/user/ocrflow should be 200, got $code"
grep -q 'id="ocrflow-panel"' /tmp/ocrflow-panel.html || fail "the Vue mount point is missing from the page"
grep -q 'ocrflow-panel\.js' /tmp/ocrflow-panel.html || fail "the bundle was not added to the page (Util::addScript)"
ok "panel page serves the mount point and the bundle"

# The admin page is a second mount point in the same bundle. A section with no
# form renders an empty tab with no error, so assert both the mount point and the
# fact that the section appears in the admin settings navigation.
echo "== the admin page renders"
code=$(curl -s -o /tmp/ocrflow-admin.html -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  "$BASE/settings/admin/ocrflow-admin")
[ "$code" = "200" ] || fail "settings/admin/ocrflow-admin should be 200, got $code"
grep -q 'id="ocrflow-admin-panel"' /tmp/ocrflow-admin.html || fail "the admin mount point is missing"
grep -q 'ocrflow-panel\.js' /tmp/ocrflow-admin.html || fail "the bundle was not added to the admin page"
ok "admin page serves the mount point and the bundle"

# The admin endpoints are admin-only. A non-admin must be refused; the same
# session must be allowed to read its own settings.
echo "== settings endpoints are admin-only"
code=$(curl -s -o /dev/null -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/settings")
[ "$code" = "200" ] || fail "admin should read /settings, got $code"
code=$(curl -s -o /dev/null -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/me")
[ "$code" = "200" ] || fail "a normal user should read /me, got $code"
ok "settings and me answer the admin session"

echo "== migration landed on the real database"
for table in "$JOBS" "$FILES" "$SETTINGS"; do
  n=$(sql "SELECT count(*) FROM information_schema.tables WHERE table_name='${table}'")
  [ "$n" = "1" ] || fail "table ${table} missing"
done
ok "tables exist: ${JOBS} ${FILES} ${SETTINGS}"

# The engine column was first added by editing an already-shipped migration, so
# an instance that installed before it has a jobs table without the column and
# dies on the first insert. A fresh install gets it from Version001000; assert
# it either way, so schema drift fails here and not on a user's instance.
engcol=$(sql "SELECT COALESCE((SELECT 1 FROM information_schema.columns WHERE table_name='${JOBS}' AND column_name='engine'),0)")
[ "$engcol" = "1" ] || fail "${JOBS} has no engine column (upgrade path broken)"
ok "jobs table has the engine column"

echo "== the constraint that makes 'do the work once' true"
# One index definition per line (pg_indexes), then each assertion is made
# against a single definition: matching over the concatenation of all of
# them lets a UNIQUE on (file_id, etag) followed by the plain (path) index
# satisfy both regexes at once and defeat the check.
indexes=$(sql "SELECT COALESCE(string_agg(indexdef, E'\n'), '') FROM pg_indexes WHERE tablename='${JOBS}'")
echo "$indexes" | grep -qE 'UNIQUE.*\(.*file_id.*etag|UNIQUE.*\(.*etag.*file_id' \
  || fail "no unique index on (file_id, etag); got: $indexes"
if echo "$indexes" | grep -E 'UNIQUE.*\(\s*"?path"?\s*[,)]' | grep -qv '^$'; then
  fail "a unique index on path is back; that re-OCRs every renamed file"
fi
ok "jobs unique on (file_id, etag), not on path"

echo "== worker plane refuses anyone without the secret"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/next")
[ "$code" = "401" ] || fail "no secret should be 401, got $code"
ok "no secret -> 401"

secret=$(occ ocrflow:worker:secret | tr -d '\r' | grep -oE '[A-Za-z0-9_-]{20,}' | tail -1)
[ -n "$secret" ] || fail "occ ocrflow:worker:secret printed nothing usable"
ok "secret issued (${#secret} chars)"

# Nextcloud 34 keeps app config in a "fast cache", and the CLI's cache is not the
# web SAPI's cache: right after issuing the secret, the running web process still
# answers "worker secret not configured" until its own cache is dropped. In
# production that is a php-fpm reload; here, a graceful apache restart. Without
# this the very next assertion fails for a reason that has nothing to do with the
# code — and an operator would hit exactly the same hour of confusion.
$ENGINE exec "$NAME" apache2ctl -k graceful >/dev/null 2>&1 || true
sleep 3

code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: definitely-not-the-secret" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/next")
[ "$code" = "401" ] || fail "wrong secret should be 401, got $code"
ok "wrong secret -> 401"

claim() {
  # Accept: application/json — the worker sends it (queue_client), and without
  # it OCS answers XML, which would make a JSON-shaped assertion below lie.
  curl -s -X POST -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
    -H "X-OcrFlow-Secret: ${secret}" \
    -o /tmp/ocrflow-claim-body -w '%{http_code}' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/next"
}

code=$(claim)
[ "$code" = "204" ] || fail "empty queue should be 204, got $code body=$(cat /tmp/ocrflow-claim-body)"
ok "empty queue -> 204"

echo "== an upload reaches the listener and becomes a claimable job"
printf '\xff\xd8\xff\xe0' > /tmp/ocrflow-up.jpg   # enough for the name test; the worker never runs here
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-up.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/scan.jpg" || fail "WebDAV upload failed"
sleep 2

jobs=$(sql "SELECT count(*) FROM ${JOBS}")
[ "${jobs:-0}" -ge 1 ] || fail "an upload produced no job (listener or enqueue is broken)"
ok "upload produced ${jobs} job(s)"

# The api transport has the app serve the bytes, so the worker never holds a
# Nextcloud credential. A build once passed the file handle as StreamResponse's
# $status: every fetch answered 500 and every job died with "server error 500
# fetching job N", while the claim itself stayed green — the failure was only
# visible to a byte-level fetch.
echo "== the worker can fetch a job's bytes from the app"
jobfile=$(sql "SELECT id FROM ${JOBS} ORDER BY id DESC LIMIT 1")
code=$(claim)
[ "$code" = "200" ] || fail "claim on a non-empty queue should be 200, got $code"
ok "claim -> 200 with a job"

# The claim response must carry the token: it is how every later call on
# this job proves it is the claim holder. An earlier build guarded nothing —
# `claimed_by` was a name from a header, so any secret holder could finish
# any job by sending the same name.
grep -q '"claim_token"' /tmp/ocrflow-claim-body || fail "claim response has no claim_token field"
token=$(grep -oE '"claim_token":"[^"]+"' /tmp/ocrflow-claim-body | cut -d'"' -f4)
[ -n "$token" ] || fail "claim_token could not be extracted from the claim response"
ok "claim response carries a claim token (${#token} chars)"

# Without the token, the byte endpoint refuses: the shared secret gates the
# worker plane, the token scopes WHAT a claim holder may touch. Until 0.1.6
# any secret holder could read any job's bytes by id.
code=$(curl -s -o /tmp/ocrflow-bytes -w '%{http_code}' -H 'OCS-APIRequest: true' -H "X-OcrFlow-Secret: ${secret}" \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobfile}/file")
[ "$code" = "409" ] || fail "file fetch without the claim token should be 409, got $code"
ok "file fetch without the token -> 409"

code=$(curl -s -o /tmp/ocrflow-bytes -w '%{http_code}' -H 'OCS-APIRequest: true' -H "X-OcrFlow-Secret: ${secret}" \
  -H "X-OcrFlow-Claim: ${token}" \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobfile}/file")
[ "$code" = "200" ] || fail "file fetch with the claim token should be 200, got $code"
[ -s /tmp/ocrflow-bytes ] || fail "file fetch returned an empty body"
ok "job bytes served by the app to the claim holder ($(wc -c < /tmp/ocrflow-bytes) bytes)"

echo "== the instance logged no error while handling our events"
log_errors=$($ENGINE exec "$NAME" sh -c 'grep -i ocrflow /var/www/html/data/nextcloud.log 2>/dev/null | grep -E "\"level\":(3|4)"' 2>/dev/null | tail -3 || true)
# A queueing failure logs at level 2, not 3, so the level filter above cannot
# see it: `enqueue()` asked the connection for the insert id, Nextcloud 34 moved
# that method to the query builder, and every upload warned "could not queue"
# while the row still landed.
warn_errors=$($ENGINE exec "$NAME" sh -c 'grep -i ocrflow /var/www/html/data/nextcloud.log 2>/dev/null | grep -E "could not queue|QueueProtocolError"' 2>/dev/null | tail -3 || true)
if [ -n "$warn_errors" ]; then
  echo "$warn_errors" | cut -c1-400
  fail "the instance logged a queueing failure (see above)"
fi
ok "no queueing failures in the log"

if [ -n "$log_errors" ]; then
  echo "$log_errors" | cut -c1-500
  fail "the instance logged an error while handling our events (see above)"
fi
ok "no level-3+ log lines mentioning ocrflow"

# The claim payload must carry the engine: an earlier build dropped it, and the
# worker then used its own env — on the api transport that is unset, so every
# job died with "no engine configured" no matter what the admin chose.
eng=$(sql "SELECT COALESCE(engine,'') FROM ${JOBS} ORDER BY id DESC LIMIT 1")
[ "$eng" = "tesseract" ] || fail "the claimed job carries engine='${eng}', expected tesseract"
ok "claimed job carries engine: tesseract"

# The claim RESPONSE must say so too: the row is not what the worker reads, the
# payload is. An earlier build wrote the column but never returned it, and the
# worker then used its own env — the exact break this asserts against.
grep -q '"engine"' /tmp/ocrflow-claim-body || fail "claim response has no engine field"
grep -q 'tesseract' /tmp/ocrflow-claim-body || fail "claim response does not carry the engine"
ok "claim response carries engine: tesseract"

# Only the worker holding the claim may close a job. A stale claim is
# requeued (its worker stopped heartbeating) and can be re-claimed while
# the first worker is still on a huge PDF; its late result must be refused
# with a 409 naming error_worker_job_reference_stale, and logged — until
# 0.1.6 it was discarded silently (200, applied=false, no log line).
echo "== only the claim holder may finish a job"
jobid=$(sql "SELECT id FROM ${JOBS} ORDER BY id DESC LIMIT 1")
code=$(curl -s -o /tmp/ocrflow-stale-body -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: definitely-not-the-token" \
  -d 'status=done' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result")
[ "$code" = "409" ] || fail "a wrong token's result should be 409, got $code"
grep -q 'error_worker_job_reference_stale' /tmp/ocrflow-stale-body || fail "the 409 body does not name the reason code"
status=$(sql "SELECT status FROM ${JOBS} WHERE id=${jobid}")
[ "$status" = "claimed" ] || fail "a wrong token's result changed the row to ${status}"
ok "a wrong token's result is refused with 409 and the row is untouched"

echo "== the queue does not hand the same version out twice"
code=$(claim)
[ "$code" = "204" ] || fail "second claim should be 204 (already claimed), got $code"
ok "second claim -> 204"

echo "== a reported failure is recorded, not lost"
id=$(sql "SELECT id FROM ${JOBS} ORDER BY id DESC LIMIT 1")
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" -d 'error=integration-test' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${id}/failure")
[ "$code" = "200" ] || fail "failure report should be 200, got $code"
status=$(sql "SELECT status FROM ${JOBS} WHERE id=${id}")
[ "$status" = "error" ] || fail "job status should be error after a reported failure, got ${status}"
ok "failure recorded as ${status}"

echo "== a dead worker's claim is taken back, and the attempt is counted"
id=$(sql "SELECT id FROM ${JOBS} ORDER BY id DESC LIMIT 1")
# A claim with no registered holder (the curl claim sent no worker id) is
# recovered by the first reconcile tick: the holder is unknown, therefore
# presumed dead. Recovery is evidence-based — the holder's heartbeat — not
# a clock window on claimed_at.
sql "UPDATE ${JOBS} SET status='claimed', claimed_at=$(date +%s), attempts=0 WHERE id=${id}" >/dev/null
# Reset the job's last_run so Nextcloud considers it due; matching on the class
# name needs LIKE, because every backslash in a PHP namespace is an escape in
# two languages at once and the first attempt silently matched nothing.
sql "UPDATE oc_jobs SET last_run=0 WHERE class LIKE '%ReconcileJob'" >/dev/null 2>&1 || true
jobid=$(sql "SELECT id FROM oc_jobs WHERE class LIKE '%ReconcileJob' LIMIT 1")
reconcile_out=$(occ background-job:execute --force-execute "$jobid" 2>&1 || true)
echo "   (reconcile: $(echo "$reconcile_out" | tr -d '\n' | cut -c1-160))"
status=$(sql "SELECT status FROM ${JOBS} WHERE id=${id}")
[ "$status" = "queued" ] || fail "a claim older than the stale window must go back to queued, still ${status}"
ok "stale claim requeued"

# The attention worklist has two front doors reading the same list(), and
# the shipped 0.1.6 killed both at once: predicate() was declared `: string`
# while orX() returns a CompositeExpression, so PHP threw a TypeError on the
# real Nextcloud — while the unit suite stayed green, because no unit test
# built a query builder. Assert BOTH doors here, on the real instance,
# where a type lie cannot hide: the endpoint must answer 200 with a JSON
# body carrying rows, and the occ command must exit 0.
echo "== the attention worklist answers on both front doors"
code=$(curl -s -o /tmp/ocrflow-attention.json -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/attention")
[ "$code" = "200" ] || fail "GET /api/v1/attention should be 200, got $code body=$(cat /tmp/ocrflow-attention.json)"
grep -q '"rows"' /tmp/ocrflow-attention.json || fail "attention response has no rows field: $(cat /tmp/ocrflow-attention.json)"
ok "attention endpoint -> 200 with rows"

if ! occ ocrflow:errors >/tmp/ocrflow-errors.log 2>&1; then
  echo "-- occ ocrflow:errors said:"; tail -8 /tmp/ocrflow-errors.log || true
  fail "occ ocrflow:errors exited non-zero"
fi
ok "occ ocrflow:errors exits 0 ($(grep -c '' /tmp/ocrflow-errors.log) lines)"

echo "ALL INTEGRATION ASSERTIONS PASSED"
