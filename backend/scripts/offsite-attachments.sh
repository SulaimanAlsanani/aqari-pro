#!/usr/bin/env bash
# Encrypt and upload all private local/S3 attachment bytes referenced in Aqari Pro.
# Requires a first-party private Laravel host and access to BOTH source disks.
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 0 ]]; then
  echo "Usage: environment variables + bash offsite-attachments.sh" >&2
  exit 2
fi

for variable in AQARI_BACKUP_RECIPIENT AQARI_BACKUP_BUCKET AQARI_BACKUP_WORKDIR AQARI_LARAVEL_ROOT; do
  if [[ -z "$(printenv "$variable" || true)" ]]; then
    echo "Missing mandatory attachment backup setting: $variable" >&2
    exit 2
  fi
done

[[ "$AQARI_BACKUP_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{2,62}$ ]] || {
  echo "Invalid destination bucket." >&2
  exit 2
}

prefix="$(printenv AQARI_ATTACHMENTS_BACKUP_PREFIX || true)"
[[ -n "$prefix" ]] || prefix="aqari-pro/attachments"
if [[ ! "$prefix" =~ ^[A-Za-z0-9_/-]+$ || "$prefix" == /* || "$prefix" == *..* ]]; then
  echo "Invalid attachment object key prefix." >&2
  exit 2
fi
prefix="$(printf '%s' "$prefix" | sed 's:/*$::')"

for executable in age aws tar php od; do
  command -v "$executable" >/dev/null 2>&1 || {
    echo "Missing attachment backup executable: $executable" >&2
    exit 2
  }
done

repo="$(cd "$(dirname "$0")/../.." && pwd)"
workdir="$(realpath -- "$AQARI_BACKUP_WORKDIR")"
laravel="$(realpath -- "$AQARI_LARAVEL_ROOT")"
case "$workdir" in
  "$repo"|"$repo"/*|"/"|"/var/www"|"/var/www/"*)
    echo "Private workdir must not be inside the repository or public web root." >&2
    exit 2
    ;;
esac
[[ -d "$workdir" && -w "$workdir" && -f "$laravel/artisan" ]] || {
  echo "Private workdir or Laravel runtime is unavailable." >&2
  exit 2
}

scratch="$(mktemp -d "$workdir/aqari-attachments.XXXXXXXX")"
cleanup() {
  rm -f -- "$scratch/attachments.tar.age" "$scratch/complete.txt"
  rm -rf -- "$scratch/source"
  rmdir -- "$scratch" 2>/dev/null || true
}
trap cleanup EXIT

mkdir -m 700 -- "$scratch/source"

# Exporting all attachment objects may be much larger than the database.
# The app must have permission to read each private local/S3 object.
(
  cd "$laravel"
  php artisan aqari:export-attachments "$scratch/source" --no-interaction
  php artisan aqari:verify-attachment-export "$scratch/source" --no-interaction
) >/dev/null

ciphertext="$scratch/attachments.tar.age"
tar -C "$scratch/source" -cf - attachments.json objects |
  age -e -r "$AQARI_BACKUP_RECIPIENT" -o "$ciphertext"

chmod 600 "$ciphertext"
[[ -s "$ciphertext" ]] || {
  echo "Encrypted attachment archive is empty." >&2
  exit 1
}

# Do not retain plaintext while contacting the remote S3 destination.
rm -rf -- "$scratch/source"

nonce="$(od -An -N16 -tx1 /dev/urandom | tr -d '[:space:]')"
timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
key="$prefix/$timestamp-$nonce.tar.age"

aws s3api put-object --bucket "$AQARI_BACKUP_BUCKET" --key "$key" \
  --body "$ciphertext" --server-side-encryption AES256 \
  --checksum-algorithm SHA256 >/dev/null

remote_size="$(aws s3api head-object --bucket "$AQARI_BACKUP_BUCKET" \
  --key "$key" --query ContentLength --output text)"
local_size="$(wc -c < "$ciphertext" | tr -d '[:space:]')"
if [[ "$remote_size" != "$local_size" ]]; then
  echo "Remote attachment archive size mismatch." >&2
  exit 1
fi

# A completion object is written ONLY after the encrypted upload has passed
# its size verification. Freshness monitors ignore unfinished .tar.age files.
printf 'ciphertext_sha256=%s\n' "$(sha256sum "$ciphertext" | cut -d ' ' -f 1)" > "$scratch/complete.txt"
chmod 600 "$scratch/complete.txt"
aws s3api put-object --bucket "$AQARI_BACKUP_BUCKET" --key "$key.ok" \
  --body "$scratch/complete.txt" --server-side-encryption AES256 \
  --checksum-algorithm SHA256 >/dev/null

echo "Encrypted private attachment backup stored; remote size verified."
echo "Remote object key: $key"
echo "WARNING: coordinate this object snapshot with a PostgreSQL backup for recovery."
