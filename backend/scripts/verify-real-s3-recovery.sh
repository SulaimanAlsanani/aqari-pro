#!/usr/bin/env bash
# Private recovery host ONLY. Downloads real encrypted S3 archives and restores
# exclusively into a pre-created, empty aqari_restore_* PostgreSQL database.
set -Eeuo pipefail
set +x
umask 077

for field in AQARI_VERIFY_BUCKET AQARI_VERIFY_DB_KEY AQARI_VERIFY_ATTACHMENTS_KEY AQARI_VERIFY_AGE_IDENTITY AQARI_VERIFY_WORKDIR AQARI_VERIFY_RESTORE_DB AQARI_VERIFY_LARAVEL_ROOT PGHOST PGUSER PGPASSWORD; do
  [[ -n "$(printenv "$field" || true)" ]] || { echo "Missing recovery setting: $field" >&2; exit 2; }
done
[[ "$AQARI_VERIFY_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{2,62}$ ]] || exit 2
[[ "$AQARI_VERIFY_RESTORE_DB" =~ ^aqari_restore_[A-Za-z0-9_]+$ ]] || exit 2
for key in "$AQARI_VERIFY_DB_KEY" "$AQARI_VERIFY_ATTACHMENTS_KEY"; do
  [[ "$key" =~ ^[A-Za-z0-9_./-]+\.tar\.age$ && "$key" != /* && "$key" != *..* ]] || exit 2
done
[[ -f "$AQARI_VERIFY_AGE_IDENTITY" && ! -L "$AQARI_VERIFY_AGE_IDENTITY" ]] || exit 2
# Group/other permission bits must both be zero.
permissions="$(stat -c %a "$AQARI_VERIFY_AGE_IDENTITY")"
(( (8#$permissions & 8#077) == 0 )) || { echo "Unsafe recovery key permissions." >&2; exit 2; }
for binary in aws age tar sha256sum pg_restore psql php; do
  command -v "$binary" >/dev/null 2>&1 || { echo "Missing recovery tool: $binary" >&2; exit 2; }
done

workdir="$(realpath -- "$AQARI_VERIFY_WORKDIR")"
laravel="$(realpath -- "$AQARI_VERIFY_LARAVEL_ROOT")"
scriptdir="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$scriptdir/../.." && pwd)"
case "$workdir" in
  "$repo"|"$repo"/*|"/"|"/var/www"|"/var/www/"*) echo 'Unsafe recovery workspace.' >&2; exit 2 ;;
esac
[[ -d "$workdir" && -w "$workdir" && -f "$laravel/artisan" ]] || exit 2
[[ ! -f "$laravel/bootstrap/cache/config.php" ]] || {
  echo "Cached Laravel config could point at production; aborting." >&2; exit 2;
}
[[ -z "$(printenv DB_DATABASE || true)" || "$DB_DATABASE" == "$AQARI_VERIFY_RESTORE_DB" ]] || {
  echo "Conflicting DB_DATABASE; refusing recovery test." >&2; exit 2;
}

scratch="$(mktemp -d "$workdir/aqari-recovery.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
mkdir -m 700 "$scratch/db" "$scratch/files"

fetch_verified() {
  local key="$1" outfile="$2" expected="" actual=""
  aws s3api get-object --bucket "$AQARI_VERIFY_BUCKET" --key "$key" --checksum-mode ENABLED "$outfile" >/dev/null
  aws s3api get-object --bucket "$AQARI_VERIFY_BUCKET" --key "$key.ok" "$outfile.ok" >/dev/null
  expected="$(sed -n 's/^ciphertext_sha256=//p' "$outfile.ok")"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || { echo 'Invalid S3 completion marker.' >&2; return 1; }
  actual="$(sha256sum "$outfile" | cut -d ' ' -f 1)"
  [[ "$expected" == "$actual" ]] || { echo 'S3 ciphertext SHA-256 mismatch.' >&2; return 1; }
}

fetch_verified "$AQARI_VERIFY_DB_KEY" "$scratch/db.tar.age"
fetch_verified "$AQARI_VERIFY_ATTACHMENTS_KEY" "$scratch/files.tar.age"
age -d -i "$AQARI_VERIFY_AGE_IDENTITY" -o "$scratch/db.tar" "$scratch/db.tar.age"
age -d -i "$AQARI_VERIFY_AGE_IDENTITY" -o "$scratch/files.tar" "$scratch/files.tar.age"

dbnames="$(tar -tf "$scratch/db.tar" | LC_ALL=C sort)"
dbexpected="$(printf '%s\n' database.dump database.sha256 manifest.txt | LC_ALL=C sort)"
[[ "$dbnames" == "$dbexpected" ]] || { echo 'Unexpected database archive members.' >&2; exit 1; }
unexpected="$(tar -tf "$scratch/files.tar" | grep -Ev '^(attachments\.json|objects/|objects/[0-9]+\.bin)$' || true)"
[[ -z "$unexpected" ]] || { echo 'Unsafe attachment archive paths.' >&2; exit 1; }
if tar -tvf "$scratch/files.tar" | grep -E '^[lhcbps]'; then
  echo 'Unsafe attachment archive link or special file.' >&2; exit 1
fi

tar --no-same-owner --no-same-permissions -C "$scratch/db" -xf "$scratch/db.tar"
tar --no-same-owner --no-same-permissions -C "$scratch/files" -xf "$scratch/files.tar"
bash "$scriptdir/pg-restore-verify.sh" "$scratch/db" "$AQARI_VERIFY_RESTORE_DB"

(
  cd "$laravel"
  port="$(printenv PGPORT || true)"
  [[ -n "$port" ]] || port=5432
  DB_CONNECTION=pgsql DB_HOST="$PGHOST" DB_PORT="$port" \
    DB_DATABASE="$AQARI_VERIFY_RESTORE_DB" DB_USERNAME="$PGUSER" DB_PASSWORD="$PGPASSWORD" \
    php artisan aqari:verify-attachment-export "$scratch/files" --no-interaction
)
echo 'REAL S3 encrypted backup, isolated PostgreSQL restore and attachment SHA-256 verification passed.'
echo 'Recovery database retained for independent checks; source data was not modified.'
