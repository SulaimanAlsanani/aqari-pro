#!/usr/bin/env bash
# Non-destructive rehearsal: restores a verified archive ONLY into an
# already-created, empty database named aqari_restore_*.
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 2 ]]; then
  echo "Usage: bash pg-restore-verify.sh BACKUP_DIRECTORY aqari_restore_unique_name" >&2
  exit 2
fi

backup_dir="$(realpath -- "$1")"
target="$2"
if [[ ! "$target" =~ ^aqari_restore_[A-Za-z0-9_]+$ ]]; then
  echo "Target must be a dedicated database named aqari_restore_*." >&2
  exit 2
fi

archive="$backup_dir/database.dump"
checksum="$backup_dir/database.sha256"
manifest="$backup_dir/manifest.txt"
for file in "$archive" "$checksum" "$manifest"; do
  [[ -f "$file" && ! -L "$file" ]] || { echo "Missing or linked backup component." >&2; exit 2; }
done
source_database="$(sed -n 's/^source_database=//p' "$manifest")"
if [[ ! "$source_database" =~ ^[A-Za-z0-9_]+$ || "$target" == "$source_database" ]]; then
  echo "Invalid backup source or unsafe destination database." >&2
  exit 2
fi
if ! grep -qx 'attachment_objects_included=no' "$manifest"; then
  echo "Unrecognized backup manifest: attachment coverage is not declared." >&2
  exit 2
fi

(
  cd "$backup_dir"
  sha256sum --status --check database.sha256
) || { echo "Backup checksum mismatch." >&2; exit 1; }
pg_restore --list "$archive" >/dev/null

PGHOST="$(printenv PGHOST || printenv DB_HOST || true)"
PGPORT="$(printenv PGPORT || printenv DB_PORT || true)"
PGUSER="$(printenv PGUSER || printenv DB_USERNAME || true)"
PGPASSWORD="$(printenv PGPASSWORD || printenv DB_PASSWORD || true)"
[[ -n "$PGPORT" ]] || PGPORT=5432
export PGHOST PGPORT PGUSER PGPASSWORD
if [[ -z "$PGHOST" || -z "$PGUSER" ]]; then
  echo "Missing PostgreSQL host or user credentials." >&2
  exit 2
fi

# Abort if the target is not empty. Never drop, replace or alter a database
# automatically. Provision a new disposable DB using the admin console first.
existing="$(psql -X --dbname="$target" --no-psqlrc -Atq -v ON_ERROR_STOP=1 -c "
  SELECT count(*) FROM pg_catalog.pg_class c
  JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public' AND c.relkind IN ('r','p');
")"
if [[ "$existing" != "0" ]]; then
  echo "Restore destination contains tables; refusing to overwrite." >&2
  exit 2
fi

# Single transaction ensures a failed restore does not leave partial data.
pg_restore --exit-on-error --single-transaction --no-owner --no-acl \
  --dbname="$target" "$archive"

expected_tables="$(psql -X --dbname="$target" --no-psqlrc -Atq -v ON_ERROR_STOP=1 -c "
 SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'
 AND table_name IN (
 'users','owners','properties','units','tenants','contracts','dues',
 'payments','attachments','attachment_events','payment_events','migrations',
 'owner_login_events'
 );
")"
if [[ "$expected_tables" != "13" ]]; then
  echo "Restored schema does not contain all expected Aqari Pro tables." >&2
  exit 1
fi

# The restored database remains intact for explicit functional tests and
# inspection. Never silently remove it or touch the source database.
echo "Backup checksum verified and restored into an isolated, previously empty database."
echo "NOTE: Attachment objects require a separate recovery and integrity verification."
