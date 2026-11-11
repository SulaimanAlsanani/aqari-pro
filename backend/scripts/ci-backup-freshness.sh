#!/usr/bin/env bash
# CI-only backup freshness contract tests using mocked S3 object metadata.
set -Eeuo pipefail
root="$(mktemp -d)"
trap 'rm -rf -- "$root"' EXIT
mkdir -p "$root/bin"
cat > "$root/bin/aws" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$1" == "s3api" && "$2" == "list-objects-v2" ]] || exit 23
prefix=''
while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --prefix) prefix="$2"; shift 2 ;;
    *) shift ;;
  esac
done
[[ "$(printenv MOCK_STORAGE_FAILURE || true)" != "1" ]] || exit 24
if [[ "$(printenv MOCK_MISSING_ATTACHMENTS || true)" == "1" && "$prefix" == *attachments/* ]]; then
  printf 'None\n'
  exit 0
fi
if [[ "$(printenv MOCK_STALE || true)" == "1" ]]; then
  date -u -d '3 days ago' '+%Y-%m-%dT%H:%M:%SZ'
else
  date -u -d '1 hour ago' '+%Y-%m-%dT%H:%M:%SZ'
fi
MOCK
chmod 700 "$root/bin/aws"
export PATH="$root/bin:$PATH"
export AQARI_BACKUP_BUCKET=aqari-private-recovery
export AQARI_BACKUP_PREFIX=aqari-pro/postgresql
export AQARI_ATTACHMENTS_BACKUP_PREFIX=aqari-pro/attachments
export AQARI_BACKUP_MAX_AGE_HOURS=30
repo="$(cd "$(dirname "$0")/../.." && pwd)"
check="$repo/backend/scripts/check-backup-freshness.sh"
bash "$check" >/dev/null

if MOCK_MISSING_ATTACHMENTS=1 bash "$check" >/dev/null 2>&1; then
  echo 'Missing attachment backup passed freshness check.' >&2; exit 1
fi
if MOCK_STALE=1 bash "$check" >/dev/null 2>&1; then
  echo 'Stale backup passed freshness check.' >&2; exit 1
fi
if MOCK_STORAGE_FAILURE=1 bash "$check" >/dev/null 2>&1; then
  echo 'S3 outage passed freshness check.' >&2; exit 1
fi
if AQARI_BACKUP_MAX_AGE_HOURS=0 bash "$check" >/dev/null 2>&1; then
  echo 'Invalid freshness policy passed.' >&2; exit 1
fi
if AQARI_BACKUP_PREFIX='../unsafe' bash "$check" >/dev/null 2>&1; then
  echo 'Unsafe backup prefix passed.' >&2; exit 1
fi
echo 'CI backup freshness missing/stale/outage/policy checks passed.'
