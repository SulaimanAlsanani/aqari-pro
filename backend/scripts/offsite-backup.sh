#!/usr/bin/env bash
# Encrypted, private, off-site PostgreSQL snapshot to an S3-compatible bucket.
# No plaintext database or secrets are uploaded to object storage.
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 0 ]]; then
  echo "Usage: environment variables + bash offsite-backup.sh (no CLI secrets)" >&2
  exit 2
fi

for variable in AQARI_BACKUP_RECIPIENT AQARI_BACKUP_BUCKET AQARI_BACKUP_WORKDIR; do
  if [[ -z "$(printenv "$variable" || true)" ]]; then
    echo "Missing mandatory backup configuration: $variable" >&2
    exit 2
  fi
done
if [[ ! "$AQARI_BACKUP_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{2,62}$ ]]; then
  echo "Invalid private object bucket name." >&2
  exit 2
fi

prefix="$(printenv AQARI_BACKUP_PREFIX || true)"
[[ -n "$prefix" ]] || prefix="aqari-pro/postgresql"
if [[ ! "$prefix" =~ ^[A-Za-z0-9_/-]+$ || "$prefix" == /* || "$prefix" == *..* ]]; then
  echo "Invalid backup object key prefix." >&2
  exit 2
fi
prefix="$(printf '%s' "$prefix" | sed 's:/*$::')"

for binary in age aws tar pg_dump pg_restore sha256sum od; do
  command -v "$binary" >/dev/null 2>&1 || {
    echo "Missing backup executable: $binary" >&2
    exit 2
  }
done

# Reject anything under public source/web directories. A private 0700
# mktemp subdirectory protects the short-lived unencrypted pg_dump.
root="$(cd "$(dirname "$0")/../.." && pwd)"
workdir="$(realpath -- "$AQARI_BACKUP_WORKDIR")"
case "$workdir" in
  "$root"|"$root"/*|"/"|"/var/www"|"/var/www/"*)
    echo "Private backup workdir must be outside the application/web root." >&2
    exit 2
    ;;
esac
[[ -d "$workdir" && -w "$workdir" ]] || {
  echo "Backup workdir does not exist or is not writable." >&2
  exit 2
}

scratch="$(mktemp -d "$workdir/aqari-offsite.XXXXXXXX")"
cleanup() {
  rm -f -- "$scratch/backup.tar.age"
  rm -f -- "$scratch/complete.txt"
  rm -rf -- "$scratch/source"
  rmdir -- "$scratch" 2>/dev/null || true
}
trap cleanup EXIT

bash "$root/backend/scripts/pg-backup.sh" "$scratch/source" >/dev/null

ciphertext="$scratch/backup.tar.age"
tar -C "$scratch/source" -cf - database.dump database.sha256 manifest.txt |
  age -e -r "$AQARI_BACKUP_RECIPIENT" -o "$ciphertext"
chmod 600 "$ciphertext"
[[ -s "$ciphertext" ]] || {
  echo "Encrypted archive is empty; refusing upload." >&2
  exit 1
}

# Remove the unencrypted database BEFORE touching off-site storage.
rm -rf -- "$scratch/source"
nonce="$(od -An -N16 -tx1 /dev/urandom | tr -d '[:space:]')"
timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
key="$prefix/$timestamp-$nonce.tar.age"

# Keep the bucket private at the infrastructure level. S3 encryption at rest
# is additional; age already provides authenticated end-to-end encryption.
aws s3api put-object --bucket "$AQARI_BACKUP_BUCKET" --key "$key" \
  --body "$ciphertext" --server-side-encryption AES256 \
  --checksum-algorithm SHA256 >/dev/null

remote_size="$(aws s3api head-object --bucket "$AQARI_BACKUP_BUCKET" \
  --key "$key" --query ContentLength --output text)"
local_size="$(wc -c < "$ciphertext" | tr -d '[:space:]')"
if [[ "$remote_size" != "$local_size" ]]; then
  echo "Remote backup object size could not be verified; alert operator." >&2
  exit 1
fi

# A completion object is written ONLY after the encrypted upload has passed
# its size verification. Freshness monitors ignore unfinished .tar.age files.
printf 'ciphertext_sha256=%s\n' "$(sha256sum "$ciphertext" | cut -d ' ' -f 1)" > "$scratch/complete.txt"
chmod 600 "$scratch/complete.txt"
aws s3api put-object --bucket "$AQARI_BACKUP_BUCKET" --key "$key.ok" \
  --body "$scratch/complete.txt" --server-side-encryption AES256 \
  --checksum-algorithm SHA256 >/dev/null

echo "Encrypted off-site database snapshot stored and remote size checked."
echo "Remote object key: $key"
echo "WARNING: Attachment objects are NOT included; schedule a separate object-storage backup."
