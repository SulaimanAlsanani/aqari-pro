#!/usr/bin/env bash
# Start Aqari Pro only on the current computer using Docker Compose.
set -Eeuo pipefail
umask 077

here="$(cd "$(dirname "$0")" && pwd)"
envfile="$here/.env.local"
compose="$here/compose.yaml"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. Install Docker Desktop/Engine first." >&2
  exit 2
fi
if ! docker compose version >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
  echo "Start Docker Desktop/Engine and ensure 'docker compose' is available." >&2
  exit 2
fi

if [[ ! -e "$envfile" ]]; then
  if ! command -v openssl >/dev/null 2>&1; then
    echo "OpenSSL is required for generating new local-only secrets." >&2
    exit 2
  fi
  appkey="base64:$(openssl rand -base64 32 | tr -d '\n')"
  dbpass="$(openssl rand -hex 32)"
  (
    set -C
    printf 'AQARI_APP_KEY=%s\nAQARI_DB_PASSWORD=%s\nAQARI_LOCAL_PORT=8080\n' "$appkey" "$dbpass" > "$envfile"
  )
  chmod 600 "$envfile"
  echo "Created private local configuration: backend/deploy/local/.env.local"
fi

# Compose validates all required values and refuses unset/empty passwords.
docker compose --env-file "$envfile" -f "$compose" config --quiet
docker compose --env-file "$envfile" -f "$compose" up --detach --build --wait db migrate app

port="$(sed -n 's/^AQARI_LOCAL_PORT=//p' "$envfile" | tail -n1)"
[[ -n "$port" ]] || port=8080
if [[ ! "$port" =~ ^[0-9]+$ ]] || (( port < 1024 || port > 65535 )); then
  echo "Invalid local port; use a port between 1024 and 65535." >&2
  exit 2
fi
address="http://localhost:$port"
if command -v curl >/dev/null 2>&1; then
  curl --noproxy '*' --fail --silent --show-error "$address/up" >/dev/null
fi
echo
echo "Aqari Pro local backend is running: $address/staging/"
echo "Database data is kept in a persistent private Docker volume."
echo
echo "Create your first owner using:"
echo "  docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml exec app php artisan aqari:provision-owner you@example.com --name='Owner Name'"
echo
echo "Optional: update ClamAV signatures before testing file uploads:"
echo "  docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml --profile maintenance run --rm clamav-update"
echo
echo "Stop safely (preserving database): docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml down"
