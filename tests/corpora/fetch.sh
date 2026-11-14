#!/usr/bin/env bash
# Download the PDF corpus the canary measures, and prove it is the corpus the
# baseline was measured on.
#
# The files are 63 MB of real-world damaged PDFs from govdocs1. They are NOT in
# this repository (see docs/corpus.md): a test canary that ships its own input
# bytes turns every clone into a 63 MB checkout, and nobody reviews a binary
# diff. So they are fetched on demand into tests/corpora/cache/ — which is
# git-ignored — and pinned by sha256 in tests/corpora/manifest.json.
#
# Usage:
#   bash tests/corpora/fetch.sh                # download what is missing, verify all
#   bash tests/corpora/fetch.sh --verify-only  # verify only; never touches the network
#   bash tests/corpora/fetch.sh --clean        # delete the cache, exit
#
# CACHE_DIR overrides the destination (the workflow keeps it inside the
# workspace so the report is uploaded from a predictable path).
set -euo pipefail

here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
manifest="$here/manifest.json"
cache="${CACHE_DIR:-$here/cache}"

mode=download
case "${1:-}" in
  --verify-only) mode=verify ;;
  --clean) rm -rf -- "$cache"; echo "removed $cache"; exit 0 ;;
  "") ;;
  *) echo "usage: $0 [--verify-only|--clean]" >&2; exit 2 ;;
esac

[ -f "$manifest" ] || { echo "missing $manifest" >&2; exit 2; }

# One line per entry: <set> <name> <bytes> <sha256> <url>. Parsed with the
# stdlib so this script needs nothing but bash, curl, sha256sum and python3.
entries() {
  python3 - "$manifest" <<'PY'
import json, sys
for f in json.load(open(sys.argv[1]))["files"]:
    print(f["set"], f["name"], f["bytes"], f["sha256"], f["url"])
PY
}

total=0
ok=0
failed=()
mkdir -p -- "$cache"

while read -r set name bytes sha url; do
  total=$((total + 1))
  dest="$cache/$set-$name"

  if [ "$mode" = download ] && [ ! -f "$dest" ]; then
    echo "fetch $set/$name ($bytes bytes)"
    if ! curl -fsSL --retry 3 --retry-delay 2 -o "$dest.part" "$url"; then
      rm -f -- "$dest.part"
      failed+=("$set/$name: download failed")
      continue
    fi
    mv -- "$dest.part" "$dest"
  fi

  if [ ! -f "$dest" ]; then
    failed+=("$set/$name: missing (run without --verify-only, or check CACHE_DIR=$cache)")
    continue
  fi

  actual_bytes=$(wc -c <"$dest" | tr -d ' ')
  if [ "$actual_bytes" != "$bytes" ]; then
    failed+=("$set/$name: size $actual_bytes != $bytes pinned")
    continue
  fi

  actual_sha=$(sha256sum -- "$dest" | cut -d' ' -f1)
  if [ "$actual_sha" != "$sha" ]; then
    failed+=("$set/$name: sha256 $actual_sha != $sha pinned")
    continue
  fi

  ok=$((ok + 1))
done < <(entries)

if [ ${#failed[@]} -ne 0 ]; then
  echo >&2
  echo "corpus verification FAILED: $ok/$total verified" >&2
  printf '  %s\n' "${failed[@]}" >&2
  # A mismatch is not a flaky download: either the pin is wrong or upstream
  # replaced a file. Either way the measurement would differ from the baseline,
  # so the bytes are removed and the run stops here instead of reporting on them.
  for f in "${failed[@]}"; do
    set_name=${f%%:*}
    rm -f -- "$cache/${set_name%%/*}-${set_name##*/}"
  done
  exit 1
fi

echo "corpus OK: $ok/$total files verified in $cache"
