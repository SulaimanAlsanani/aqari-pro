#!/usr/bin/env bash
# Executed by GitHub Codespaces after a development container starts.
set -Eeuo pipefail
if [[ "${CODESPACES:-}" != "true" || -z "${CODESPACE_NAME:-}" ||
      -z "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]]; then
  echo "Codespaces automatic start is restricted to a GitHub Codespace." >&2
  exit 2
fi

cd "$(dirname "$0")/.."
echo "Starting Aqari Pro Docker/PostgreSQL development workspace."

ready=0
for attempt in $(seq 1 45); do
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
if [[ "$ready" != "1" ]]; then
  echo "Docker engine unavailable; inspect the docker-in-docker Codespaces feature." >&2
  exit 1
fi

bash backend/deploy/local/start.sh --codespaces
