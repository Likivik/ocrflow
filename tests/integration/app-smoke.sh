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

# --- verdict at birth: the write-back settles the produced file, and the
# scan decides from the ledger's facts plus the current bar ------------------
#
# The PHPUnit harness cannot run real SQL, so the plan's [real-db] tests land
# here, on the real instance, where the migration, the ledger and the scan
# all meet actual rows:
#   * a produced file's ledger row (status=skipped, reason=skip_ocred_by_ocrflow,
#     engine facts as written) exists right after the write-back;
#   * a scan after the job rows are purged creates NO job for the settled
#     bytes at/above the bar (the 8135 loop), and none below the bar either
#     (a redo is a candidate, never automatic);
#   * a different etag (new bytes) IS queued;
#   * moving the bar changes only the refusal, never the row;
#   * the loop breaker: a second scan enqueue for identical bytes is refused
#     with error_queued_twice_for_same_bytes (needs_fix), the operator's
#     requeue resets the counter and works exactly once, and a scan of an
#     unchanged archive inserts zero rows.
echo "== verdict at birth: the ledger settles what the write-back produced"

# The scan skips files younger than its quiescence window; the fixtures are
# seconds old, so the window is zero for this section.
occ config:app:set ocrflow scan_min_age_seconds --value 0 >/dev/null

# One scan pass, run to completion (the endpoint only queues the ScanJob).
scan_once() {
  sql "UPDATE oc_jobs SET last_run=0 WHERE class LIKE '%ScanJob'" >/dev/null 2>&1 || true
  code=$(curl -s -o /dev/null -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" -X POST \
    -H 'OCS-APIRequest: true' -H 'Accept: application/json' -d 'path=/' \
    "$BASE/ocs/v2.php/apps/ocrflow/api/v1/scan")
  [ "$code" = "200" ] || fail "POST /scan should be 200, got $code"
  scanjob=$(sql "SELECT id FROM oc_jobs WHERE class LIKE '%ScanJob' ORDER BY id DESC LIMIT 1")
  occ background-job:execute --force-execute "$scanjob" >/dev/null 2>&1 \
    || fail "the ScanJob did not run (oc_jobs id ${scanjob})"
}

# Claim the one job an upload just created; sets jobid and token.
claim_one() {
  code=$(claim)
  [ "$code" = "200" ] || fail "claim should be 200 (a job was just uploaded), got $code"
  jobid=$(grep -oE '"id":[0-9]+' /tmp/ocrflow-claim-body | head -1 | cut -d: -f2)
  token=$(grep -oE '"claim_token":"[^"]+"' /tmp/ocrflow-claim-body | cut -d'"' -f4)
  [ -n "$jobid" ] || fail "could not read the claimed job's id"
  [ -n "$token" ] || fail "could not read the claimed job's token"
}

jobs_for() { sql "SELECT count(*) FROM ${JOBS} WHERE path LIKE '%/$1'"; }

# -- [real-db] test 6: the write-back settles the produced file -------------
sql "DELETE FROM ${JOBS}" >/dev/null   # this section starts from an empty queue
printf '\xff\xd8\xff\xe0settled' > /tmp/ocrflow-settled.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-settled.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/settled.jpg" || fail "WebDAV upload of settled.jpg failed"
sleep 2
claim_one
printf '%%PDF-1.7 produced by ocrflow\n' > /tmp/ocrflow-produced.pdf
code=$(curl -s -o /tmp/ocrflow-resultfile -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result-file?name=settled.pdf&engine=google&engine_version=3.15.0")
[ "$code" = "200" ] || fail "result-file should be 200, got $code body=$(cat /tmp/ocrflow-resultfile)"
grep -q '"file_id"' /tmp/ocrflow-resultfile || fail "result-file response has no file_id"
grep -q '"etag"' /tmp/ocrflow-resultfile || fail "result-file response has no etag"
settle_reason=$(sql "SELECT reason FROM ${FILES} WHERE path LIKE '%/settled.pdf'")
[ "$settle_reason" = "skip_ocred_by_ocrflow" ] \
  || fail "the produced file's ledger row should be settled with skip_ocred_by_ocrflow, got '${settle_reason}'"
settle_engine=$(sql "SELECT engine || ' ' || engine_version FROM ${FILES} WHERE path LIKE '%/settled.pdf'")
[ "$settle_engine" = "google 3.15.0" ] \
  || fail "the settle must record the engine facts as written, got '${settle_engine}'"
ok "write-back settled the produced file (google 3.15.0, skip_ocred_by_ocrflow)"

# -- [real-db] tests 1+8+11: a settled row at the bar survives a purge and a
# scan; a second scan of the unchanged archive inserts nothing -------------
sql "DELETE FROM ${JOBS}" >/dev/null   # retention's work
scan_once
[ "$(jobs_for settled.pdf)" = "0" ] \
  || fail "the scan re-queued the produced file it had just settled (the 8135 loop)"
ok "settled at the bar: scan queued nothing for the produced file"
before=$(sql "SELECT count(*) FROM ${JOBS}")
scan_once
[ "$(sql "SELECT count(*) FROM ${JOBS}")" = "$before" ] \
  || fail "a second scan of an unchanged archive inserted rows"
ok "second scan of the unchanged archive inserted 0 rows"

# -- [real-db] test 2: new bytes (a different etag) are queued --------------
printf '%%PDF-1.7 edited by the user\n' > /tmp/ocrflow-settled.pdf
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-settled.pdf \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/settled.pdf" || fail "WebDAV overwrite of settled.pdf failed"
sleep 2
sql "DELETE FROM ${JOBS}" >/dev/null   # the listener's job is not the scan's verdict
scan_once
[ "$(jobs_for settled.pdf)" -ge 1 ] \
  || fail "a settled file with NEW bytes (different etag) must be queued"
ok "settled with new bytes: scan queued the new version"

# -- [real-db] tests 3+4: below the bar is not queued, and moving the bar
# changes only the refusal, never the row -----------------------------------
sql "DELETE FROM ${JOBS}" >/dev/null
printf '\xff\xd8\xff\xe0below' > /tmp/ocrflow-below.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-below.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/below.jpg" || fail "WebDAV upload of below.jpg failed"
sleep 2
claim_one
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result-file?name=below.pdf&engine=google&engine_version=3.9.0")
[ "$code" = "200" ] || fail "result-file for below.pdf should be 200, got $code"
below_row=$(sql "SELECT etag || ' ' || reason FROM ${FILES} WHERE path LIKE '%/below.pdf'")
sql "DELETE FROM ${JOBS}" >/dev/null
scan_once
[ "$(jobs_for below.pdf)" = "0" ] \
  || fail "a settled row BELOW the bar was re-queued automatically: a redo is a candidate, never automatic"
below_after=$(sql "SELECT etag || ' ' || reason FROM ${FILES} WHERE path LIKE '%/below.pdf'")
[ "$below_row" = "$below_after" ] \
  || fail "the below-bar refusal rewrote the ledger row ('${below_row}' -> '${below_after}')"
ok "below the bar: not queued, ledger row untouched"

# Lowering the bar below the stamp changes only the verdict the panel would
# report — no row rewrite, no enqueue.
occ config:app:set ocrflow ocr_accepted_engines --value '{"google":"9.9.9"}' >/dev/null
sql "DELETE FROM ${JOBS}" >/dev/null
scan_once
[ "$(jobs_for below.pdf)" = "0" ] || fail "lowering the bar must not re-OCR automatically"
below_after=$(sql "SELECT etag || ' ' || reason FROM ${FILES} WHERE path LIKE '%/below.pdf'")
[ "$below_row" = "$below_after" ] \
  || fail "moving the bar rewrote the ledger row ('${below_row}' -> '${below_after}')"
occ config:app:set ocrflow ocr_accepted_engines --value '{"google":"3.15.0"}' >/dev/null
ok "bar moved below the stamp: still no enqueue, still no row rewrite"

# -- [real-db] tests 7+9: the loop breaker and the operator override ---------
printf '\xff\xd8\xff\xe0breaker' > /tmp/ocrflow-breaker.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-breaker.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/breaker.jpg" || fail "WebDAV upload of breaker.jpg failed"
sleep 2
sql "DELETE FROM ${JOBS}" >/dev/null
scan_once
[ "$(jobs_for breaker.jpg)" = "1" ] || fail "the first scan enqueue of breaker.jpg must go through"
counted=$(sql "SELECT times_enqueued FROM ${FILES} WHERE path LIKE '%/breaker.jpg'")
[ "$counted" = "1" ] || fail "the scan enqueue must count in the ledger, got times_enqueued=${counted}"
sql "DELETE FROM ${JOBS}" >/dev/null   # the first verdict is gone: the loop's opening
scan_once
twice=$(sql "SELECT reason_code FROM ${JOBS} WHERE path LIKE '%/breaker.jpg' LIMIT 1")
[ "$twice" = "error_queued_twice_for_same_bytes" ] \
  || fail "the second scan enqueue must be refused with error_queued_twice_for_same_bytes, got '${twice}'"
twice_status=$(sql "SELECT status FROM ${JOBS} WHERE path LIKE '%/breaker.jpg' LIMIT 1")
[ "$twice_status" = "error" ] || fail "the refusal row must be an error row, got ${twice_status}"
ledger_status=$(sql "SELECT status FROM ${FILES} WHERE path LIKE '%/breaker.jpg'")
[ "$ledger_status" = "error" ] || fail "the ledger must carry the loop verdict past the purge, got ${ledger_status}"
ok "second scan enqueue refused: error_queued_twice_for_same_bytes, needs_fix"

# The operator's door: the requeue resets the counter and works exactly once.
# The path exactly as the ledger recorded it (findFileByPath compares raw).
breaker_path=$(sql "SELECT path FROM ${FILES} WHERE path LIKE '%/breaker.jpg' LIMIT 1")
[ -n "$breaker_path" ] || fail "no ledger row for breaker.jpg to requeue"
requeue_out=$(occ ocrflow:requeue "$breaker_path")
echo "$requeue_out" | grep -q "cleared the failure verdict" \
  || fail "occ ocrflow:requeue did not clear: $requeue_out"
counted=$(sql "SELECT times_enqueued FROM ${FILES} WHERE path LIKE '%/breaker.jpg'")
[ "$counted" = "0" ] || fail "the requeue must reset times_enqueued, got ${counted}"
sql "DELETE FROM ${JOBS}" >/dev/null
scan_once
[ "$(jobs_for breaker.jpg)" = "1" ] || fail "after the requeue the scan must queue the file exactly once"
sql "DELETE FROM ${JOBS}" >/dev/null
scan_once
twice=$(sql "SELECT reason_code FROM ${JOBS} WHERE path LIKE '%/breaker.jpg' LIMIT 1")
[ "$twice" = "error_queued_twice_for_same_bytes" ] \
  || fail "the breaker must be armed again after the one requeue, got '${twice}'"
ok "requeue resets the counter and buys exactly one more enqueue"

# --- Parts A + C, [real-db]: the write-time ladder and the stash -----------
#
# The PHPUnit harness cannot run real SQL, so the plan's ladder/stash tests
# that need the real database, the real appdata tree and the real WebDAV
# etag behaviour land here:
#   * the 0.1.8 migration: jobs.source_sha256, jobs.written_etag, the
#     pending table with UNIQUE (file_id, etag);
#   * `file()` hashes the exact bytes it served into the job row;
#   * rung 2 of the ladder: identical bytes under a new etag still get
#     the result written;
#   * rung 3: genuinely different bytes are not written over — the result
#     is stashed into appdata (never the user's files), the job closes as
#     a terminal skip_source_changed_result_saved, and a later result()
#     call for that job is a no-op;
#   * the in-place path: a successful putFile re-arms written_etag, and a
#     retried putFile over our own write is allowed, not stashed;
#   * artifact retention: an expired pending row's bytes are deleted by the
#     reconcile tick, the row stays behind as discarded, and one log line
#     per tick carries the total artifact bytes.
echo "== the ladder and the stash: the 0.1.8 schema"
n=$(sql "SELECT count(*) FROM information_schema.tables WHERE table_name='oc_ocrflow_pending_results'")
[ "$n" = "1" ] || fail "oc_ocrflow_pending_results is missing (migration 001009 did not land)"
pidx=$(sql "SELECT COALESCE(string_agg(indexdef, E'\n'), '') FROM pg_indexes WHERE tablename='oc_ocrflow_pending_results'")
echo "$pidx" | grep -qE 'UNIQUE.*file_id.*etag|UNIQUE.*etag.*file_id' \
  || fail "no unique index on (file_id, etag) for the pending table; got: $pidx"
for col in source_sha256 written_etag; do
  c=$(sql "SELECT COALESCE((SELECT 1 FROM information_schema.columns WHERE table_name='${JOBS}' AND column_name='${col}'),0)")
  [ "$c" = "1" ] || fail "${JOBS} has no ${col} column (migration 001009 did not land)"
done
ok "pending table unique on (file_id, etag); jobs carries source_sha256 and written_etag"

sql "DELETE FROM ${JOBS}" >/dev/null   # this section starts from an empty queue
printf '\xff\xd8\xff\xe0conflict-source-bytes' > /tmp/ocrflow-conflict.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-conflict.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/conflict.jpg" || fail "WebDAV upload of conflict.jpg failed"
sleep 2
claim_one

echo "== the ladder: file() hashes the exact bytes it serves"
code=$(curl -s -o /tmp/ocrflow-conflict-bytes -w '%{http_code}' -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/file")
[ "$code" = "200" ] || fail "fetching the conflict job's bytes should be 200, got $code"
served_hash=$(sha256sum /tmp/ocrflow-conflict-bytes | cut -d' ' -f1)
row_hash=$(sql "SELECT COALESCE(source_sha256,'') FROM ${JOBS} WHERE id=${jobid}")
[ "${#served_hash}" = "64" ] || fail "the fetched bytes did not hash to 64 chars"
[ "$row_hash" = "$served_hash" ] \
  || fail "jobs.source_sha256 '${row_hash}' != sha256 of the served bytes '${served_hash}'"
ok "source_sha256 is the hash of the bytes the worker received"

echo "== the ladder, rung 2: identical bytes under a new etag still get the result"
# A re-PUT of the SAME bytes: the etag moves (mtime) but the content does not.
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-conflict.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/conflict.jpg" || fail "WebDAV re-PUT of conflict.jpg failed"
sleep 2
code=$(curl -s -o /tmp/ocrflow-rung2 -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result-file?name=conflict.pdf")
[ "$code" = "200" ] || fail "rung 2 should write the result (200), got $code body=$(cat /tmp/ocrflow-rung2)"
grep -q '"file_id"' /tmp/ocrflow-rung2 \
  || fail "rung 2 did not write: $(cat /tmp/ocrflow-rung2)"
ok "same bytes under a new etag: result written"

echo "== the ladder, rung 3: different bytes are stashed, not written over"
printf '\xff\xd8\xff\xe0edited-by-the-user' > /tmp/ocrflow-conflict-edited.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-conflict-edited.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/conflict.jpg" || fail "WebDAV overwrite of conflict.jpg failed"
sleep 2
fid=$(sql "SELECT file_id FROM ${JOBS} WHERE id=${jobid}")
code=$(curl -s -o /tmp/ocrflow-stash -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result-file?name=conflict2.pdf&engine=google&engine_version=3.15.0&pages=2")
[ "$code" = "200" ] || fail "a stashed conflict must still answer 200 (terminal skip), got $code body=$(cat /tmp/ocrflow-stash)"
grep -q '"stashed":true' /tmp/ocrflow-stash \
  || fail "the stash response does not say stashed:true: $(cat /tmp/ocrflow-stash)"
row=$(sql "SELECT status || ' ' || COALESCE(reason_code,'') || ' ' || COALESCE(retryable::text,'') FROM ${JOBS} WHERE id=${jobid}")
[ "$row" = "skipped skip_source_changed_result_saved false" ] \
  || fail "the job should close as a terminal skip_source_changed_result_saved, got '${row}'"
prow=$(sql "SELECT count(*) FROM oc_ocrflow_pending_results WHERE file_id=${fid} AND status='pending'")
[ "$prow" = "1" ] || fail "expected one pending row for file_id=${fid}, got ${prow}"
artifact=$($ENGINE exec "$NAME" sh -c 'ls /var/www/html/data/appdata_*/ocrflow/pending-results/ 2>/dev/null | grep -c pending-')
[ "$artifact" = "1" ] || fail "expected 1 artifact in appdata, got '${artifact}'"
ok "conflict stashed: terminal skip, pending row, artifact in appdata"

echo "== the stash: a later result() call is a no-op"
code=$(curl -s -o /tmp/ocrflow-result-noop -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  -d 'status=done' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result")
[ "$code" = "200" ] || fail "result() for a stashed job should be a 200 no-op, got $code body=$(cat /tmp/ocrflow-result-noop)"
grep -q 'stashed' /tmp/ocrflow-result-noop \
  || fail "the no-op response does not say the result was stashed: $(cat /tmp/ocrflow-result-noop)"
row=$(sql "SELECT status || ' ' || COALESCE(reason_code,'') FROM ${JOBS} WHERE id=${jobid}")
[ "$row" = "skipped skip_source_changed_result_saved" ] \
  || fail "the no-op result() changed the job row to '${row}'"
prow=$(sql "SELECT count(*) FROM oc_ocrflow_pending_results WHERE file_id=${fid} AND status='pending'")
[ "$prow" = "1" ] || fail "the no-op result() touched the pending rows (${prow})"
ok "result() after a stash: terminal 200, nothing done"

echo "== the ladder, in place: written_etag re-arms and a retried putFile passes"
sql "DELETE FROM ${JOBS}" >/dev/null
printf '\xff\xd8\xff\xe0inplace-source' > /tmp/ocrflow-inplace.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-inplace.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/inplace.jpg" || fail "WebDAV upload of inplace.jpg failed"
sleep 2
claim_one
code=$(curl -s -o /tmp/ocrflow-inplace-fetch -w '%{http_code}' -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/file")
[ "$code" = "200" ] || fail "fetching the inplace job's bytes should be 200, got $code"
code=$(curl -s -o /tmp/ocrflow-put1 -w '%{http_code}' -X PUT -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/file?engine=google&engine_version=3.15.0")
[ "$code" = "200" ] || fail "the first putFile should be 200, got $code body=$(cat /tmp/ocrflow-put1)"
armed=$(sql "SELECT COALESCE(written_etag,'') FROM ${JOBS} WHERE id=${jobid}")
[ -n "$armed" ] || fail "a successful putFile did not re-arm written_etag"
# The retried upload: the etag on disk is our own write's, so the ladder must
# let it through instead of stashing it (the measured in-place bug).
code=$(curl -s -o /tmp/ocrflow-put2 -w '%{http_code}' -X PUT -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/file")
[ "$code" = "200" ] || fail "a retried putFile over our own write should be 200, got $code body=$(cat /tmp/ocrflow-put2)"
grep -q '"stashed":true' /tmp/ocrflow-put2 \
  && fail "the retried putFile was stashed: the written_etag rung did not recognise our own write"
row=$(sql "SELECT status FROM ${JOBS} WHERE id=${jobid}")
[ "$row" = "claimed" ] || fail "putFile should not close the job, got ${row}"
ok "putFile re-arms written_etag; a retried upload passes the ladder"

echo "== the stash: retention sweeps the expired artifacts"
# The artifact line is an info-level line; a fresh instance logs warnings
# only, so let info through before the tick we want to observe.
occ config:system:set loglevel --type integer --value 1 >/dev/null 2>&1 || true
sql "UPDATE oc_ocrflow_pending_results SET created_at=0 WHERE file_id=${fid}" >/dev/null
sql "UPDATE oc_jobs SET last_run=0 WHERE class LIKE '%ReconcileJob'" >/dev/null 2>&1 || true
reconcilejob=$(sql "SELECT id FROM oc_jobs WHERE class LIKE '%ReconcileJob' LIMIT 1")
occ background-job:execute --force-execute "$reconcilejob" >/dev/null 2>&1 \
  || fail "the reconcile job did not run (oc_jobs id ${reconcilejob})"
dstate=$(sql "SELECT status FROM oc_ocrflow_pending_results WHERE file_id=${fid}")
[ "$dstate" = "discarded" ] || fail "the expired pending row should be left as discarded, got '${dstate}'"
artifact=$($ENGINE exec "$NAME" sh -c 'ls /var/www/html/data/appdata_*/ocrflow/pending-results/ 2>/dev/null | grep -c pending- || true')
[ "$artifact" = "0" ] || fail "the expired artifact bytes were not deleted (${artifact} left)"
bytes_line=$($ENGINE exec "$NAME" sh -c "grep -c 'pending result artifacts' /var/www/html/data/nextcloud.log 2>/dev/null || true")
[ "${bytes_line:-0}" -ge 1 ] || fail "the reconcile tick logged no pending-result artifact line"
ok "expired artifact swept: bytes deleted, row discarded, byte-total line logged"

# --- Part B: the outcomes reader, its two doors, and the Decide actions -----
#
# The plan's [real-db] tests for the five tabs, on the real instance where
# the ledger, the pending table and the user's file tree all meet real rows:
#   * occ ocrflow:outcomes and GET /api/v1/outcomes name the SAME redo rows
#     (one reader, two doors - the doors cannot disagree);
#   * a non-admin is refused on the outcomes endpoints;
#   * a POST without the OCS-APIRequest header is refused (CSRF);
#   * an empty selection spends nothing;
#   * the cap bounds one redo click, and the answer carries its estimate;
#   * Apply refuses while versioning is unavailable (the previous content
#     would be unrecoverable), applies once it is, and deletes the artifact;
#   * Discard deletes the artifact and keeps the row as the audit trail.
echo "== outcomes: one reader, two doors"

# The bar decides below.pdf's tab at read time; pin it so the fixture is
# stable no matter what an earlier section moved it to.
occ config:app:set ocrflow ocr_accepted_engines --value '{"google":"3.15.0"}' >/dev/null
# The cap for the redo test is set BEFORE the first outcomes request, so a
# cached app-config read cannot serve a stale default on the redo POST.
occ config:app:set ocrflow attention_retry_cap --value 1 >/dev/null

if ! occ ocrflow:outcomes --verdict=redo >/tmp/ocrflow-outcomes-occ.log 2>&1; then
  echo "-- occ ocrflow:outcomes said:"; tail -8 /tmp/ocrflow-outcomes-occ.log || true
  fail "occ ocrflow:outcomes exited non-zero"
fi
occ_paths=$(grep -E '^(ledger|stash|job) ' /tmp/ocrflow-outcomes-occ.log | awk '{print $3}' | sort)
[ -n "$occ_paths" ] \
  || fail "occ ocrflow:outcomes listed no redo rows (below.pdf and the conflict must both be there)"

code=$(curl -s -o /tmp/ocrflow-outcomes.json -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes?verdict=redo")
[ "$code" = "200" ] || fail "GET /api/v1/outcomes should be 200, got $code body=$(cat /tmp/ocrflow-outcomes.json)"
# The JSON body escapes "/" as "\/", so unescape before comparing with the
# occ table's plain paths.
api_paths=$(grep -oE '"path":"[^"]*"' /tmp/ocrflow-outcomes.json | cut -d'"' -f4 | sed 's|\\/|/|g' | sort)
[ "$occ_paths" = "$api_paths" ] \
  || fail "the endpoint and occ name different redo rows: occ=[$occ_paths] api=[$api_paths]"
grep -q '"summary"' /tmp/ocrflow-outcomes.json || fail "the outcomes response carries no summary"
grep -q '"pricing"' /tmp/ocrflow-outcomes.json || fail "the outcomes response carries no pricing"
ok "outcomes: occ and the endpoint name the same redo rows"

echo "== outcomes: admin-only, CSRF-checked, cap-bound"
$ENGINE exec -e OC_PASS=bob-pass-123 -u www-data "$NAME" php occ user:add --password-from-env bob >/dev/null 2>&1 || true
code=$(curl -s -o /tmp/ocrflow-bob -w '%{http_code}' -u "bob:bob-pass-123" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes?verdict=redo")
[ "$code" = "403" ] || fail "a non-admin must be refused on /outcomes, got $code body=$(cat /tmp/ocrflow-bob)"

code=$(curl -s -o /tmp/ocrflow-csrf -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'Accept: application/json' -d 'ids[]=1' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/redo")
[ "$code" = "412" ] || fail "a POST without the OCS header must be refused as CSRF, got $code body=$(cat /tmp/ocrflow-csrf)"

# An empty selection spends nothing: zero retries and no queue change.
before=$(sql "SELECT count(*) FROM ${JOBS}")
code=$(curl -s -o /tmp/ocrflow-empty -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"ids":[]}' "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/redo")
[ "$code" = "200" ] || fail "an empty selection should be a 200 no-op, got $code body=$(cat /tmp/ocrflow-empty)"
grep -q '"retried":0' /tmp/ocrflow-empty || fail "an empty selection must retry nothing: $(cat /tmp/ocrflow-empty)"
after=$(sql "SELECT count(*) FROM ${JOBS}")
[ "$before" = "$after" ] || fail "an empty selection changed the jobs table (${before} -> ${after})"
ok "non-admin refused; CSRF refused; empty selection spends nothing"

# The cap bounds one click: two redo rows selected, cap 1, exactly one spent.
below_id=$(sql "SELECT file_id FROM ${FILES} WHERE path LIKE '%/below.pdf'")
[ -n "$below_id" ] || fail "no ledger row for below.pdf to select"
code=$(curl -s -o /tmp/ocrflow-redo -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"ids\":[${below_id},${fid}]}" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/redo")
[ "$code" = "200" ] || fail "the capped redo should be 200, got $code body=$(cat /tmp/ocrflow-redo)"
grep -q '"retried":1' /tmp/ocrflow-redo || fail "the cap must bound one click to 1 retry: $(cat /tmp/ocrflow-redo)"
grep -q '"estimate"' /tmp/ocrflow-redo || fail "the redo answer carries no estimate: $(cat /tmp/ocrflow-redo)"
ok "the cap bounds one redo click and the answer carries its estimate"

# -- [real-db] a pre-provenance stamp (reason ours, engine NULL) is Settled
# with an explicit note, never a redo candidate; only a known below-bar
# engine goes to Decide, and a redo selection never includes the NULL row.
echo "== outcomes: a pre-provenance stamp is Settled, never Decide"
sql "INSERT INTO ${FILES} (file_id, path, status, reason, engine, engine_version, etag, updated_at) VALUES (990000001, 'admin/files/stamped-old.pdf', 'skipped', 'stamped_good_by_us', NULL, NULL, 'old-stamp', 1)" >/dev/null
code=$(curl -s -o /tmp/ocrflow-oldsettled -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes?verdict=settled")
[ "$code" = "200" ] || fail "GET settled should be 200, got $code body=$(cat /tmp/ocrflow-oldsettled)"
grep -q 'stamped-old.pdf' /tmp/ocrflow-oldsettled \
  || fail "the NULL-engine stamped row must be Settled: $(cat /tmp/ocrflow-oldsettled)"
grep -q 'engine unknown: stamped before provenance was recorded' /tmp/ocrflow-oldsettled \
  || fail "the settled pre-provenance row must carry the explicit note: $(cat /tmp/ocrflow-oldsettled)"
code=$(curl -s -o /tmp/ocrflow-oldredo -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes?verdict=redo")
[ "$code" = "200" ] || fail "GET redo should be 200, got $code body=$(cat /tmp/ocrflow-oldredo)"
if grep -q 'stamped-old.pdf' /tmp/ocrflow-oldredo; then
  fail "the NULL-engine stamped row must NEVER be a redo candidate: $(cat /tmp/ocrflow-oldredo)"
fi
grep -q 'below.pdf' /tmp/ocrflow-oldredo \
  || fail "the known below-bar row must stay a redo candidate: $(cat /tmp/ocrflow-oldredo)"
# A redo selection that names the NULL-engine row spends nothing on it: the
# estimate counts only the below-bar row, and the retry cap allows it.
old_id=$(sql "SELECT file_id FROM ${FILES} WHERE path LIKE '%/stamped-old.pdf'")
below_id2=$(sql "SELECT file_id FROM ${FILES} WHERE path LIKE '%/below.pdf'")
code=$(curl -s -o /tmp/ocrflow-oldsel -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"ids\":[${old_id},${below_id2}]}" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/redo")
[ "$code" = "200" ] || fail "the redo selection should be 200, got $code body=$(cat /tmp/ocrflow-oldsel)"
grep -q '"retried":1' /tmp/ocrflow-oldsel \
  || fail "only the below-bar row may be retried: $(cat /tmp/ocrflow-oldsel)"
grep -q '"estimate"' /tmp/ocrflow-oldsel || fail "the redo answer carries no estimate: $(cat /tmp/ocrflow-oldsel)"
sql "DELETE FROM ${FILES} WHERE file_id = ${old_id}" >/dev/null
ok "pre-provenance stamp: Settled with the note, never in Decide, never retried"

# The web caches the app-enabled state across requests; an occ flip of
# files_versions takes up to ~60s to reach the web. Polling that state
# directly needs a non-mutating probe: Apply on a stash whose FILE is gone
# is refused either way, and WHICH refusal comes back says what the web
# thinks about versioning.
sql "INSERT INTO oc_ocrflow_pending_results (file_id, etag, artifact_path, engine, engine_version, pages, created_at, status) VALUES (999999999, 'probe', 'probe.pdf', 'google', '3.15.0', 0, 0, 'pending')" >/dev/null
probe_id=$(sql "SELECT id FROM oc_ocrflow_pending_results WHERE etag='probe'")
[ -n "$probe_id" ] || fail "the versioning probe row could not be inserted"

wait_web_versions() { # $1: "unavailable" or "available"
  deadline=$(( $(date +%s) + 150 ))
  while :; do
    curl -s -o /tmp/ocrflow-versions-probe -u "${ADMIN_USER}:${ADMIN_PASS}" \
      -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d "{\"id\":${probe_id}}" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/apply" >/dev/null
    if [ "$1" = "unavailable" ]; then
      grep -q 'versioning is unavailable' /tmp/ocrflow-versions-probe && return 0
    else
      grep -q 'the file this stash belongs to is gone' /tmp/ocrflow-versions-probe && return 0
    fi
    [ "$(date +%s)" -lt "$deadline" ] \
      || fail "the web never saw files_versions $1: $(cat /tmp/ocrflow-versions-probe)"
    sleep 5
  done
}

echo "== outcomes: Apply and Discard for a stashed result"
# A fresh stash: upload, claim, change the bytes under it, hand in a result
# for the OLD bytes - the ladder stashes it instead of overwriting.
# Purge earlier sections' leftover queued rows first, so claim_one claims
# THIS upload's job and not a stale one.
sql "DELETE FROM ${JOBS} WHERE status='queued'" >/dev/null
printf '\xff\xd8\xff\xe0decide-source' > /tmp/ocrflow-decide.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-decide.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/decide.jpg" || fail "WebDAV upload of decide.jpg failed"
sleep 2
claim_one
# Fetch the claimed bytes first: that is what arms source_sha256, so the
# later overwrite is a detectable conflict (the ladder's stash rung).
curl -s -o /tmp/ocrflow-decide-bytes -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/file" >/dev/null
printf '\xff\xd8\xff\xe0decide-edited' > /tmp/ocrflow-decide-edited.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-decide-edited.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/decide.jpg" || fail "WebDAV overwrite of decide.jpg failed"
code=$(curl -s -o /tmp/ocrflow-decide-stash -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result-file?name=decide.pdf&engine=google&engine_version=3.15.0&pages=2")
[ "$code" = "200" ] || fail "the decide stash should answer 200, got $code body=$(cat /tmp/ocrflow-decide-stash)"
grep -q '"stashed":true' /tmp/ocrflow-decide-stash \
  || fail "the decide result was not stashed: $(cat /tmp/ocrflow-decide-stash)"
stash_id=$(sql "SELECT id FROM oc_ocrflow_pending_results WHERE status='pending' ORDER BY id DESC LIMIT 1")
[ -n "$stash_id" ] || fail "no pending row for the decide stash"

# Versioning off: Apply must refuse - overwriting would be unrecoverable.
occ app:disable files_versions >/dev/null 2>&1 || true
wait_web_versions unavailable
code=$(curl -s -o /tmp/ocrflow-apply-refused -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"id\":${stash_id}}" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/apply")
[ "$code" = "409" ] || fail "Apply without versioning should be a 409 refusal, got $code body=$(cat /tmp/ocrflow-apply-refused)"
grep -q '"warning"' /tmp/ocrflow-apply-refused \
  || fail "the Apply refusal must carry the warning a human reads: $(cat /tmp/ocrflow-apply-refused)"
state=$(sql "SELECT status FROM oc_ocrflow_pending_results WHERE id=${stash_id}")
[ "$state" = "pending" ] || fail "a refused Apply must leave the stash pending, got '${state}'"
ok "Apply refuses with a warning while versioning is unavailable"

# Versioning on: Apply writes the artifact over the file and closes the row.
occ app:enable files_versions >/dev/null 2>&1 || fail "could not enable files_versions"
wait_web_versions available
code=$(curl -s -o /tmp/ocrflow-apply -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"id\":${stash_id}}" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/apply")
[ "$code" = "200" ] || fail "Apply with versioning should be 200, got $code body=$(cat /tmp/ocrflow-apply)"
grep -q '"applied"' /tmp/ocrflow-apply || fail "the apply answer should say applied: $(cat /tmp/ocrflow-apply)"
state=$(sql "SELECT status FROM oc_ocrflow_pending_results WHERE id=${stash_id}")
[ "$state" = "applied" ] || fail "the applied row should be closed as applied, got '${state}'"

# Discard on a second fresh stash: bytes deleted, row kept.
sql "DELETE FROM ${JOBS} WHERE status='queued'" >/dev/null
printf '\xff\xd8\xff\xe0discard-source' > /tmp/ocrflow-discard.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-discard.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/discard.jpg" || fail "WebDAV upload of discard.jpg failed"
sleep 2
claim_one
# Arm source_sha256 the same way, so the overwrite conflicts.
curl -s -o /tmp/ocrflow-discard-bytes -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/file" >/dev/null
printf '\xff\xd8\xff\xe0discard-edited' > /tmp/ocrflow-discard-edited.jpg
curl -s -o /dev/null -u "${ADMIN_USER}:${ADMIN_PASS}" -X PUT --data-binary @/tmp/ocrflow-discard-edited.jpg \
  "$BASE/remote.php/dav/files/${ADMIN_USER}/discard.jpg" || fail "WebDAV overwrite of discard.jpg failed"
code=$(curl -s -o /tmp/ocrflow-discard-stash -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H 'Accept: application/json' -H "X-OcrFlow-Secret: ${secret}" -H "X-OcrFlow-Claim: ${token}" \
  --data-binary @/tmp/ocrflow-produced.pdf \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${jobid}/result-file?name=discard.pdf&engine=google&engine_version=3.15.0&pages=1")
[ "$code" = "200" ] || fail "the discard stash should answer 200, got $code body=$(cat /tmp/ocrflow-discard-stash)"
discard_id=$(sql "SELECT id FROM oc_ocrflow_pending_results WHERE status='pending' ORDER BY id DESC LIMIT 1")
artifact_name=$(sql "SELECT artifact_path FROM oc_ocrflow_pending_results WHERE id=${discard_id}")
code=$(curl -s -o /tmp/ocrflow-discard-done -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"id\":${discard_id}}" "$BASE/ocs/v2.php/apps/ocrflow/api/v1/outcomes/discard")
[ "$code" = "200" ] || fail "Discard should be 200, got $code body=$(cat /tmp/ocrflow-discard-done)"
state=$(sql "SELECT status FROM oc_ocrflow_pending_results WHERE id=${discard_id}")
[ "$state" = "discarded" ] || fail "the discarded row should be kept as discarded, got '${state}'"
left=$($ENGINE exec "$NAME" sh -c "ls /var/www/html/data/appdata_*/ocrflow/pending-results/ 2>/dev/null | grep -c '${artifact_name}' || true")
[ "$left" = "0" ] || fail "the discarded artifact bytes were not deleted (${artifact_name} left)"
ok "Apply writes with versioning; Discard deletes the bytes and keeps the row"

# The probe row served its purpose; it is not an outcome.
sql "DELETE FROM oc_ocrflow_pending_results WHERE id=${probe_id}" >/dev/null

# Restore the default cap for whoever runs the instance after the smoke.
occ config:app:set ocrflow attention_retry_cap --value 500 >/dev/null

echo "ALL INTEGRATION ASSERTIONS PASSED"

