#!/usr/bin/env bash
# Encrypt and upload a snapshot of Laravel's PRIVATE local attachment directory.
# S3-backed attachment objects require their own bucket backup/replication.
set -Eeuo pipefail
umask 077

if [[ "$#" -ne 0 ]]; then
  echo "Usage: configure environment, then run offsite-local-attachments.sh" >&2
  exit 2
fi
for required in AQARI_BACKUP_RECIPIENT AQARI_BACKUP_BUCKET AQARI_BACKUP_WORKDIR AQARI_PRIVATE_ATTACHMENTS_DIR; do
  if [[ -z "$(printenv "$required" || true)" ]]; then
    echo "Missing mandatory attachment backup configuration: $required" >&2
    exit 2
  fi
done

source="$(realpath -- "$AQARI_PRIVATE_ATTACHMENTS_DIR")"
workdir="$(realpath -- "$AQARI_BACKUP_WORKDIR")"
root="$(cd "$(dirname "$0")/../.." && pwd)"
case "$source" in
  "$root"|"$root"/*|"/"|"/var/www"|"/var/www/"*)
    echo "Attachment source must be a private directory outside the web root." >&2
    exit 2 ;;
esac
case "$workdir" in
  "$root"|"$root"/*|"/"|"/var/www"|"/var/www/"*)
    echo "Backup workspace must be outside the application/web root." >&2
    exit 2 ;;
esac

[[ -d "$source" && -r "$source" && -d "$workdir" && -w "$workdir" ]] || {
  echo "Attachment source or private workspace is not accessible." >&2
  exit 2
}
case "$source/" in
  "$workdir/"*) echo "Attachment source cannot include backup scratch files." >&2; exit 2 ;;
esac
case "$workdir/" in
  "$source/"*) echo "Backup scratch files cannot be inside attachment source." >&2; exit 2 ;;
esac

# Prevent tar from traversing symbolic links or ambiguous special files.
if find "$source" \( -type l -o -type b -o -type c -o -type p -o -type s \) -print -quit | grep -q .; then
  echo "Private attachment source contains a link or special file; refusing snapshot." >&2
  exit 2
fi

for binary in age aws tar sha256sum od; do
  command -v "$binary" >/dev/null 2>&1 || {
    echo "Missing backup executable: $binary" >&2
    exit 2
  }
done
if [[ ! "$AQARI_BACKUP_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{2,62}$ ]]; then
  echo "Invalid private backup bucket." >&2; exit 2
fi
prefix="$(printenv AQARI_ATTACHMENT_BACKUP_PREFIX || true)"
[[ -n "$prefix" ]] || prefix='aqari-pro/local-attachments'
if [[ ! "$prefix" =~ ^[A-Za-z0-9_/-]+$ || "$prefix" == /* || "$prefix" == *..* ]]; then
  echo "Invalid attachment backup prefix." >&2; exit 2
fi
prefix="$(printf '%s' "$prefix" | sed 's:/*$::')"

scratch="$(mktemp -d "$workdir/aqari-attachments.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT

# Generate a deterministic per-file checksum manifest for the recovery drill.
# GNU find/sha256sum -z preserve unusual attachment filenames without parsing lines.
(
  cd "$source"
  find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum
) > "$scratch/files.sha256"
chmod 600 "$scratch/files.sha256"

ciphertext="$scratch/attachments.tar.age"
tar -C "$source" --sort=name --numeric-owner --owner=0 --group=0 -cf - . \
  -C "$scratch" files.sha256 | age -e -r "$AQARI_BACKUP_RECIPIENT" -o "$ciphertext"
[[ -s "$ciphertext" ]] || { echo "Empty encrypted archive." >&2; exit 1; }
chmod 600 "$ciphertext"

nonce="$(od -An -N16 -tx1 /dev/urandom | tr -d '[:space:]')"
stamp="$(date -u '+%Y%m%dT%H%M%SZ')"
key="$prefix/$stamp-$nonce.tar.age"

aws s3api put-object --bucket "$AQARI_BACKUP_BUCKET" --key "$key" \
  --body "$ciphertext" --server-side-encryption AES256 \
  --checksum-algorithm SHA256 >/dev/null

remote_size="$(aws s3api head-object --bucket "$AQARI_BACKUP_BUCKET" \
  --key "$key" --query ContentLength --output text)"
local_size="$(wc -c < "$ciphertext" | tr -d '[:space:]')"
if [[ "$remote_size" != "$local_size" ]]; then
  echo "Remote encrypted attachment snapshot size mismatch." >&2
  exit 1
fi

printf 'ciphertext_sha256=%s\n' "$(sha256sum "$ciphertext" | cut -d ' ' -f 1)" \
  > "$scratch/complete.txt"
aws s3api put-object --bucket "$AQARI_BACKUP_BUCKET" --key "$key.ok" \
  --body "$scratch/complete.txt" --server-side-encryption AES256 \
  --checksum-algorithm SHA256 >/dev/null

echo "Encrypted local attachment snapshot uploaded, verified, and marked complete."
echo "Remote object key: $key"
echo "S3-backed attachment objects are NOT included in this snapshot."
