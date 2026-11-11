#!/usr/bin/env bash
# Snapshot PostgreSQL into a new, private directory.
# Attachment objects on local/S3 disks are NOT included.
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 1 ]]; then
  echo "Usage: bash pg-backup.sh /private/backup-directory/new-backup" >&2
  exit 2
fi

root="$(cd "$(dirname "$0")/../.." && pwd)"
destination="$(realpath -m -- "$1")"
case "$destination" in
  "$root"|"$root"/*|"/"|"/tmp"|"/var/www"|"/var/www/"*)
    echo "Refusing a backup path under the repository, public web root, or a shared directory." >&2
    exit 2
    ;;
esac

PGHOST="$(printenv PGHOST || printenv DB_HOST || true)"
PGPORT="$(printenv PGPORT || printenv DB_PORT || true)"
PGDATABASE="$(printenv PGDATABASE || printenv DB_DATABASE || true)"
PGUSER="$(printenv PGUSER || printenv DB_USERNAME || true)"
PGPASSWORD="$(printenv PGPASSWORD || printenv DB_PASSWORD || true)"
[[ -n "$PGPORT" ]] || PGPORT=5432
export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD

for required in PGHOST PGDATABASE PGUSER; do
  if [[ -z "$(printenv "$required")" ]]; then
    echo "Missing PostgreSQL environment setting: $required" >&2
    exit 2
  fi
done

if [[ ! "$PGDATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Database name must contain only letters, numbers, or underscores." >&2
  exit 2
fi
if [[ -e "$destination" ]]; then
  echo "Backup destination exists; refusing overwrite." >&2
  exit 2
fi

mkdir -m 700 -- "$destination"
cleanup() {
  rm -f -- "$destination/database.dump" "$destination/database.sha256" "$destination/manifest.txt"
  rmdir -- "$destination" 2>/dev/null || true
}
trap cleanup ERR

# A custom-format dump represents one transaction-consistent DB snapshot.
pg_dump --format=custom --compress=6 --no-owner --no-acl \
  --dbname="$PGDATABASE" --file="$destination/database.dump"
pg_restore --list "$destination/database.dump" >/dev/null

(
  cd "$destination"
  sha256sum database.dump >database.sha256
)
{
  printf 'format=pg_custom\n'
  printf 'source_database=%s\n' "$PGDATABASE"
  printf 'created_utc=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  printf 'attachment_objects_included=no\n'
} > "$destination/manifest.txt"

chmod 600 "$destination/database.dump" "$destination/database.sha256" "$destination/manifest.txt"
trap - ERR
echo "Private PostgreSQL custom-format archive and SHA-256 checksum created."
echo "WARNING: Attachment objects and external backup encryption are NOT included."
