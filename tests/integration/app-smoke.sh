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
IMAGE="${NC_IMAGE:-docker.io/library/nextcloud:34-apache}"
NAME="ocrflow-it-$$"
PORT="${NC_PORT:-8089}"
ADMIN_USER="admin"
ADMIN_PASS="integration-pass-123"
BASE="http://127.0.0.1:${PORT}"

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

cleanup() { $ENGINE rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

occ() { $ENGINE exec -u www-data "$NAME" php occ "$@" 2>&1; }

# php inside the container, so no host sqlite3 client is needed
sqlite() {
  $ENGINE exec "$NAME" php -r '
$p = new PDO("sqlite:/var/www/html/data/nextcloud.db");
foreach ($p->query($argv[1])->fetchAll(PDO::FETCH_NUM) as $row) { echo implode("|", $row), PHP_EOL; }
' "$1"
}

echo "== starting $IMAGE as $NAME"
$ENGINE run -d --name "$NAME" -p "127.0.0.1:${PORT}:80" \
  -e SQLITE_DATABASE=nextcloud \
  -e NEXTCLOUD_ADMIN_USER="$ADMIN_USER" \
  -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
  -e NEXTCLOUD_TRUSTED_DOMAINS="127.0.0.1 localhost" \
  "$IMAGE" >/dev/null

echo "== waiting for the instance to install"
for _ in $(seq 1 60); do
  if $ENGINE exec -u www-data "$NAME" php occ status 2>/dev/null | grep -q 'installed: true'; then
    break
  fi
  sleep 2
done
$ENGINE exec -u www-data "$NAME" php occ status 2>/dev/null | grep -q 'installed: true' || fail "instance never finished installing"
ok "instance installed"

echo "== copying the app in"
$ENGINE exec "$NAME" mkdir -p /var/www/html/custom_apps/ocrflow
# Everything the app needs at run time, not just the PHP: the committed Vue
# bundle in js/ and the icon in img/ are part of the app, not build artefacts of
# the test.
for part in appinfo lib templates js img; do
  if [ -d "${APP_DIR}/${part}" ]; then
    $ENGINE cp "${APP_DIR}/${part}" "$NAME:/var/www/html/custom_apps/ocrflow/${part}"
  fi
done

occ app:enable ocrflow >/dev/null || fail "occ app:enable ocrflow"
occ app:list | grep -q ocrflow || fail "app not in app:list"
ok "app enabled"

echo "== the panel renders inside personal settings"
code=$(curl -s -o /tmp/ocrflow-panel.html -w '%{http_code}' -u "${ADMIN_USER}:${ADMIN_PASS}" \
  "$BASE/settings/user/ocrflow")
[ "$code" = "200" ] || fail "settings/user/ocrflow should be 200, got $code"
grep -q 'id="ocrflow-panel"' /tmp/ocrflow-panel.html || fail "the Vue mount point is missing from the page"
grep -q 'ocrflow-panel\.js' /tmp/ocrflow-panel.html || fail "the bundle was not added to the page (Util::addScript)"
ok "panel page serves the mount point and the bundle"

echo "== migration landed on the real database"
for table in "$JOBS" "$FILES" "$SETTINGS"; do
  n=$(sqlite "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='${table}'")
  [ "$n" = "1" ] || fail "table ${table} missing"
done
ok "tables exist: ${JOBS} ${FILES} ${SETTINGS}"

echo "== the constraint that makes 'do the work once' true"
indexes=$(sqlite "SELECT COALESCE(sql,'') FROM sqlite_master WHERE type='index' AND tbl_name='${JOBS}'")
echo "$indexes" | grep -qiE 'file_id.*etag' || fail "no unique index on (file_id, etag); got: $indexes"
if echo "$indexes" | grep -qiE 'UNIQUE.*\(.*path'; then
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
  curl -s -X POST -H 'OCS-APIRequest: true' -H "X-OcrFlow-Secret: ${secret}" \
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

jobs=$(sqlite "SELECT count(*) FROM ${JOBS}")
[ "${jobs:-0}" -ge 1 ] || fail "an upload produced no job (listener or enqueue is broken)"
ok "upload produced ${jobs} job(s)"

echo "== the instance logged no error while handling our events"
log_errors=$($ENGINE exec "$NAME" sh -c 'grep -i ocrflow /var/www/html/data/nextcloud.log 2>/dev/null | grep -E "\"level\":(3|4)"' 2>/dev/null | tail -3 || true)
if [ -n "$log_errors" ]; then
  echo "$log_errors" | cut -c1-500
  fail "the instance logged an error while handling our events (see above)"
fi
ok "no level-3+ log lines mentioning ocrflow"

code=$(claim)
[ "$code" = "200" ] || fail "claim on a non-empty queue should be 200, got $code"
ok "claim -> 200 with a job"

echo "== the queue does not hand the same version out twice"
code=$(claim)
[ "$code" = "204" ] || fail "second claim should be 204 (already claimed), got $code"
ok "second claim -> 204"

echo "== a reported failure is recorded, not lost"
id=$(sqlite "SELECT id FROM ${JOBS} LIMIT 1")
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'OCS-APIRequest: true' \
  -H "X-OcrFlow-Secret: ${secret}" -d 'error=integration-test' \
  "$BASE/ocs/v2.php/apps/ocrflow/api/v1/queue/${id}/failure")
[ "$code" = "200" ] || fail "failure report should be 200, got $code"
status=$(sqlite "SELECT status FROM ${JOBS} WHERE id=${id}")
[ "$status" = "error" ] || fail "job status should be error after a reported failure, got ${status}"
ok "failure recorded as ${status}"

echo "== a dead worker's claim is taken back, and the attempt is counted"
id=$(sqlite "SELECT id FROM ${JOBS} ORDER BY id DESC LIMIT 1")
stale=$(( $(date +%s) - 1000 ))   # older than Application::STALE_CLAIM_SECONDS (900)
sqlite "UPDATE ${JOBS} SET status='claimed', claimed_at=${stale}, attempts=0 WHERE id=${id}" >/dev/null
# Reset the job's last_run so Nextcloud considers it due; matching on the class
# name needs LIKE, because every backslash in a PHP namespace is an escape in
# two languages at once and the first attempt silently matched nothing.
sqlite "UPDATE oc_jobs SET last_run=0 WHERE class LIKE '%ReconcileJob'" >/dev/null 2>&1 || true
jobid=$(sqlite "SELECT id FROM oc_jobs WHERE class LIKE '%ReconcileJob' LIMIT 1")
reconcile_out=$(occ background-job:execute --force-execute "$jobid" 2>&1 || true)
echo "   (reconcile: $(echo "$reconcile_out" | tr -d '\n' | cut -c1-160))"
status=$(sqlite "SELECT status FROM ${JOBS} WHERE id=${id}")
[ "$status" = "queued" ] || fail "a claim older than the stale window must go back to queued, still ${status}"
ok "stale claim requeued"

echo "ALL INTEGRATION ASSERTIONS PASSED"
