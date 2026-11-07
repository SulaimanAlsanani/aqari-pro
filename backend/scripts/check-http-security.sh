#!/usr/bin/env bash
# Read-only checks against a running disposable app/staging HTTP endpoint.
set -euo pipefail

base="${AQARI_SECURITY_BASE_URL:-http://127.0.0.1:8080}"
base="${base%/}"
case "$base" in
  http://127.0.0.1:*|http://localhost:*|https://*) ;;
  *) echo "FAIL: remote security probes require HTTPS" >&2; exit 1 ;;
esac

assert_denied() {
  local path="$1" status
  status="$(curl --silent --show-error --max-time 10 --output /dev/null --write-out '%{http_code}' \
    --header 'Accept: application/json' "$base$path")"
  if [[ "$status" != "403" && "$status" != "404" ]]; then
    echo "FAIL: $path returned HTTP $status; expected 403 or 404" >&2
    exit 1
  fi
}

for path in \
  /.env \
  /.git/config \
  /vendor/autoload.php \
  /composer.json \
  /storage/logs/laravel.log \
  /storage/app/private/probe.txt; do
  assert_denied "$path"
done

for path in /api/portfolio /api/documents /api/documents/1/preview /api/documents/1; do
  status="$(curl --silent --show-error --max-time 10 --output /dev/null --write-out '%{http_code}' \
    --header 'Accept: application/json' "$base$path")"
  if [[ "$status" != "401" ]]; then
    echo "FAIL: unauthenticated $path returned HTTP $status; expected 401" >&2
    exit 1
  fi
done

headers="$(curl --silent --show-error --max-time 10 --head "$base/app/index.html" | tr -d '\r')"
if ! printf '%s\n' "$headers" | grep -qi '^x-content-type-options: nosniff$'; then
  echo "FAIL: X-Content-Type-Options: nosniff missing" >&2
  exit 1
fi
if ! printf '%s\n' "$headers" | grep -qi '^x-frame-options: DENY$'; then
  echo "FAIL: X-Frame-Options: DENY missing" >&2
  exit 1
fi
echo "PASS: private paths inaccessible, auth required and basic HTTP headers set"
