#!/usr/bin/env bash
# Run on the staging/production Docker host via a monitored scheduler.
# Updates only antivirus signatures; never migrates or edits tenant data.
set -euo pipefail

if [[ "${AQARI_CLAMAV_REFRESH_APPROVED:-}" != "signature-update" ]]; then
  echo "FAIL: Set AQARI_CLAMAV_REFRESH_APPROVED=signature-update to enable signature refresh" >&2
  exit 1
fi

for bin in docker flock; do
  command -v "$bin" >/dev/null || { echo "FAIL: $bin required" >&2; exit 1; }
done

# The lock avoids overlapping freshclam processes from cron/manual runs.
lock="${AQARI_CLAMAV_REFRESH_LOCK:-/tmp/aqari-pro-clamav-refresh.lock}"
exec 9>"$lock"
if ! flock -n 9; then
  echo "FAIL: ClamAV refresh is already running" >&2
  exit 1
fi

docker compose config --quiet
if ! docker compose ps --status running --services | grep -qx app; then
  echo "FAIL: app container is not running" >&2
  exit 1
fi

echo "Updating ClamAV signature database inside the persistent app volume..."
docker compose exec -T -u root app freshclam
echo "Validating updated signatures and harmless test scan..."
docker compose exec -T app bash scripts/check-clamav-readiness.sh
echo "PASS: ClamAV signatures refreshed and readiness validated"
