#!/bin/sh
# Encrypted, owner-operated snapshot of the Aqari Pro compose deployment.
# Run on the deployment host with Docker Compose access and the public AGE_RECIPIENT.
set -eu

: "${AGE_RECIPIENT:?Set an age X25519 public recipient (not a private key)}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
case "$BACKUP_DIR" in
  ""|"/"|".") echo "Choose a dedicated backup directory" >&2; exit 1 ;;
esac
command -v docker >/dev/null 2>&1 || { echo "docker is required" >&2; exit 1; }
command -v age >/dev/null 2>&1 || { echo "age encryption is required" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "gzip is required" >&2; exit 1; }
umask 077
mkdir -p "$BACKUP_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DB_OUT="$BACKUP_DIR/aqari-db-$STAMP.sql.gz.age"
FILES_OUT="$BACKUP_DIR/aqari-files-$STAMP.tar.gz.age"
cleanup() {
  # Always reopen the application; if backup failed do not leave partial snapshots.
  docker compose exec -T app php artisan up >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM
docker compose exec -T app php artisan down --retry=120
# Pause new HTTP requests so the SQL and private file snapshot correspond.
sleep 5
if ! docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --single-transaction --quick --no-tablespaces -u aqari aqari' |
    gzip -c | age -r "$AGE_RECIPIENT" -o "$DB_OUT"; then
  rm -f "$DB_OUT"
  echo "Database backup failed" >&2
  exit 1
fi
if ! docker compose exec -T app tar -C /var/www/html/storage -czf - app/private |
    age -r "$AGE_RECIPIENT" -o "$FILES_OUT"; then
  rm -f "$DB_OUT" "$FILES_OUT"
  echo "Private attachment backup failed" >&2
  exit 1
fi
echo "Encrypted database snapshot: $DB_OUT"
echo "Encrypted private attachment snapshot: $FILES_OUT"
echo "Transfer these backups off-host and routinely test restoration to an isolated environment."
