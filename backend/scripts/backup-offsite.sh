#!/usr/bin/env bash
# Make an encrypted snapshot, upload it to a configured off-host rclone backend,
# and verify both encrypted artifacts are visible remotely.
# Does not delete remote backups. Run on the deployment host with monitored scheduling.
set -euo pipefail
umask 077
: "${AGE_RECIPIENT:?AGE_RECIPIENT public key is required}"
remote="${AQARI_BACKUP_REMOTE:-}"
if [[ ! "$remote" =~ ^[A-Za-z0-9_-]+:.+ ]] || [[ "$remote" == *":/" ]] ||
   [[ "$remote" == *":." ]] || [[ "$remote" == *":.." ]]; then
  echo "FAIL: AQARI_BACKUP_REMOTE must be a configured remote:bucket/path (not a local directory)" >&2
  exit 1
fi
if [[ "${AQARI_BACKUP_APPROVED:-}" != "encrypted-offsite" ]]; then
  echo "FAIL: Set AQARI_BACKUP_APPROVED=encrypted-offsite only after verifying the external destination" >&2
  exit 1
fi
for bin in rclone age docker flock; do
  command -v "$bin" >/dev/null || { echo "FAIL: $bin is required" >&2; exit 1; }
done
remote_name="${remote%%:*}:"
if ! rclone listremotes | grep -Fxq "$remote_name"; then
  echo "FAIL: Requested rclone remote is not configured" >&2; exit 1
fi

lock="${AQARI_BACKUP_LOCK:-/tmp/aqari-pro-offsite-backup.lock}"
exec 9>"$lock"
if ! flock -n 9; then echo "FAIL: An offsite backup is already running" >&2; exit 1; fi

# Use an isolated private staging directory to avoid uploading incomplete/old snapshots.
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
AGE_RECIPIENT="$AGE_RECIPIENT" BACKUP_DIR="$work" bash scripts/backup-encrypted.sh
db="$(find "$work" -maxdepth 1 -name 'aqari-db-*.sql.gz.age' -type f -print -quit)"
docs="$(find "$work" -maxdepth 1 -name 'aqari-files-*.tar.gz.age' -type f -print -quit)"
if [[ -z "$db" || -z "$docs" || ! -s "$db" || ! -s "$docs" ]]; then
  echo "FAIL: Missing or empty encrypted backup artifacts" >&2; exit 1
fi

rclone copy "$work" "$remote" --include '*.age' --immutable
for file in "$db" "$docs"; do
  name="$(basename "$file")"
  if ! rclone lsf "$remote" --files-only | grep -Fxq "$name"; then
    echo "FAIL: Encrypted remote artifact not visible: $name" >&2
    exit 1
  fi
done
# Listing filenames is insufficient: verify remote bytes against the two local
# encrypted artifacts by reading them back. --one-way allows older snapshots
# to remain in the destination without failing this specific upload check.
if ! rclone check "$work" "$remote" --include '*.age' --one-way --download; then
  echo "FAIL: Remote encrypted snapshot content did not match local upload" >&2
  exit 1
fi
echo "PASS: Encrypted DB and private attachments uploaded, listed and content-verified offsite"
echo "PENDING: Independent decrypt/restore test, retention policy, backend IAM review and storage alerting"
