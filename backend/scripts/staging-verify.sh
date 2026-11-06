#!/usr/bin/env bash
# Run on an isolated staging host after docker compose up -d.
# Read-only against application data: does not migrate, seed, update signatures or alter files.
set -euo pipefail

expected="${AQARI_EXPECTED_SHA:-}"
url="${AQARI_STAGING_URL:-}"
if [[ ! "$expected" =~ ^[0-9a-f]{40}$ ]]; then
  echo "FAIL: AQARI_EXPECTED_SHA must be the full, reviewed commit SHA" >&2
  exit 1
fi
if [[ ! "$url" =~ ^https://[^/]+/?$ ]]; then
  echo "FAIL: AQARI_STAGING_URL must be an HTTPS origin without a path" >&2
  exit 1
fi
host="${url#https://}"
host="${host%/}"
case "$host" in
 localhost*|127.*|*.invalid|*.example|*.test) echo "FAIL: Use an actual isolated staging HTTPS domain" >&2; exit 1 ;;
esac
if [[ "${AQARI_STAGING_APPROVED:-}" != "staging-only" ]]; then
  echo "FAIL: Set AQARI_STAGING_APPROVED=staging-only after confirming the host is not production" >&2
  exit 1
fi

for command in git docker curl; do
  if ! command -v "$command" >/dev/null; then echo "FAIL: $command unavailable" >&2; exit 1; fi
done
current="$(git rev-parse HEAD)"
if [[ "$current" != "$expected" ]]; then
  echo "FAIL: checked-out SHA does not match the reviewed release candidate" >&2
  exit 1
fi

# Keep Compose command output quiet so passwords are never printed into CI or shell logs.
docker compose config --quiet
if [[ "$(docker compose ps --status running --services | grep -c '^app$' || true)" != "1" ]] ||
   [[ "$(docker compose ps --status running --services | grep -c '^db$' || true)" != "1" ]]; then
  echo "FAIL: app and db containers must be running" >&2
  exit 1
fi

# Apache cannot replace a real external HTTPS reverse-proxy test.
curl --fail --silent --show-error --max-time 15 "$url/up" >/dev/null
AQARI_SECURITY_BASE_URL="${url%/}" bash scripts/check-http-security.sh
docker compose exec -T app bash scripts/check-clamav-readiness.sh
docker compose exec -T app php artisan migrate:status --no-ansi >/dev/null

printf 'PASS: release SHA %s, staging HTTPS, HTTP isolation, ClamAV and migrations\n' "$current"
printf 'PENDING: SMTP receipt test, independent backup restore, cross-owner UAT and operator approval\n'
