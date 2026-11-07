#!/usr/bin/env bash
# Non-invasive monitoring probe, suitable for an external monitor or scheduler.
set -euo pipefail
origin="${AQARI_MONITOR_URL:-}"
if [[ ! "$origin" =~ ^https://[^/?#]+/?$ ]]; then
  echo "FAIL: AQARI_MONITOR_URL must be a single HTTPS origin" >&2
  exit 1
fi
origin="${origin%/}"
check() {
  local path="$1" expected="$2" status
  status="$(curl --silent --show-error --max-time 12 --output /dev/null --write-out '%{http_code}' -H 'Accept: application/json' "$origin$path")"
  if [[ "$status" != "$expected" ]]; then
    echo "FAIL: $path returned HTTP $status; expected $expected" >&2
    return 1
  fi
}
check /up 200
check /app/index.html 200
check /api/portfolio 401
echo "PASS: HTTPS health, owner portal and private API auth are responding at $origin"
