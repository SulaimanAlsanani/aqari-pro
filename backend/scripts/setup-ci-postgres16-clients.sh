#!/usr/bin/env bash
# CI-only: use PostgreSQL 16 client utilities from the same version as the
# PostgreSQL 16 GitHub service. Avoid host-installed PG 17/18 pg_dump archives,
# which may emit SET transaction_timeout unsupported by PG16.
set -Eeuo pipefail
[[ -n "$(printenv RUNNER_TEMP || true)" && -n "$(printenv GITHUB_PATH || true)" ]] || {
  echo "This tool is only for GitHub Actions." >&2; exit 2;
}
command -v docker >/dev/null 2>&1 || { echo "Docker is unavailable." >&2; exit 2; }
directory="$RUNNER_TEMP/aqari-pg16-tools"
mkdir -m 700 -- "$directory"
for tool in pg_dump pg_restore psql; do
  cat > "$directory/$tool" <<'WRAPPER'
#!/usr/bin/env bash
set -Eeuo pipefail
# The same absolute ephemeral path exists inside and outside the container.
# Run as the GitHub worker so backup archives never become root-owned.
[[ -n "$(printenv RUNNER_TEMP || true)" ]] || exit 2
client="$(basename "$0")"
exec docker run --rm --network host \
  --user "$(id -u):$(id -g)" \
  -e PGHOST -e PGPORT -e PGUSER -e PGPASSWORD -e PGDATABASE \
  -v "$RUNNER_TEMP:$RUNNER_TEMP:rw" -w "$RUNNER_TEMP" \
  postgres:16 "$client" "$@"
WRAPPER
  chmod 700 "$directory/$tool"
done
printf '%s\n' "$directory" >> "$GITHUB_PATH"
"$directory/pg_dump" --version
"$directory/pg_restore" --version
"$directory/psql" --version
