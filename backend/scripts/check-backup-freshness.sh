#!/usr/bin/env bash
# Alert by exit status when the latest successful PostgreSQL or attachment
# encrypted object backup is stale, missing, or inaccessible.
set -Eeuo pipefail
umask 077

bucket="$(printenv AQARI_BACKUP_BUCKET || true)"
[[ "$bucket" =~ ^[a-z0-9][a-z0-9.-]{2,62}$ ]] || { echo 'Invalid backup bucket.' >&2; exit 2; }
command -v aws >/dev/null || { echo 'AWS CLI unavailable.' >&2; exit 2; }

limit="$(printenv AQARI_BACKUP_MAX_AGE_HOURS || true)"
[[ -n "$limit" ]] || limit=30
[[ "$limit" =~ ^[0-9]+$ ]] && (( limit >= 1 && limit <= 720 )) || {
  echo 'Backup freshness limit must be 1–720 hours.' >&2; exit 2;
}

database="$(printenv AQARI_BACKUP_PREFIX || true)"
documents="$(printenv AQARI_ATTACHMENTS_BACKUP_PREFIX || true)"
[[ -n "$database" ]] || database='aqari-pro/postgresql'
[[ -n "$documents" ]] || documents='aqari-pro/attachments'

now="$(date -u +%s)"
failures=0
check_group() {
  local name="$1" prefix="$2" dates="" newest="" epoch="" elapsed=""
  [[ "$prefix" =~ ^[A-Za-z0-9_/-]+$ && "$prefix" != /* && "$prefix" != *..* ]] || {
    echo "$name: invalid object key prefix." >&2; return 1;
  }
  prefix="$(printf '%s' "$prefix" | sed 's:/*$::')"

  dates="$(aws s3api list-objects-v2 --bucket "$bucket" --prefix "$prefix/" \
    --query "Contents[?ends_with(Key, '.ok')].LastModified" --output text)" || {
      echo "$name: remote completion markers cannot be inspected." >&2; return 1;
    }
  newest="$(printf '%s\n' "$dates" | tr '\t' '\n' |
    grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}T' | LC_ALL=C sort | tail -n1)" || true
  [[ -n "$newest" ]] || { echo "$name: no completed encrypted backup." >&2; return 1; }
  epoch="$(date -u -d "$newest" +%s)" || { echo "$name: invalid timestamp." >&2; return 1; }
  elapsed=$((now-epoch))
  (( elapsed >= -300 && elapsed <= limit*3600 )) || {
    echo "$name: completed backup is stale or timestamp is in the future." >&2; return 1;
  }
  echo "$name: completed encrypted backup is within freshness policy."
}
check_group postgresql "$database" || failures=$((failures+1))
check_group attachments "$documents" || failures=$((failures+1))
(( failures == 0 )) || {
  echo "CRITICAL: encrypted backup freshness check failed." >&2
  exit 1
}
echo "Both encrypted backup streams passed freshness policy."
