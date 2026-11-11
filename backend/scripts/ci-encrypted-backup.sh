#!/usr/bin/env bash
# CI-only test of encrypted off-site backups using a private mock object store.
set -Eeuo pipefail
umask 077

repo="$(cd "$(dirname "$0")/../.." && pwd)"
temp="$(printenv RUNNER_TEMP || printf /tmp)"
root="$(mktemp -d "$temp/aqari-encrypted-ci.XXXXXXXX")"
trap 'rm -rf -- "$root"' EXIT

mkdir -m 700 -p "$root/work" "$root/objects" "$root/bin" "$root/decrypted"
mock="$root/bin/aws"
cat > "$mock" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$1" == "s3api" ]] || exit 22
operation="$2"
shift 2
bucket=""
key=""
body=""
encryption=""
checksum=""
while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --bucket) bucket="$2"; shift 2 ;;
    --key) key="$2"; shift 2 ;;
    --body) body="$2"; shift 2 ;;
    --server-side-encryption) encryption="$2"; shift 2 ;;
    --checksum-algorithm) checksum="$2"; shift 2 ;;
    --query|--output) shift 2 ;;
    *) echo "Unexpected aws parameter." >&2; exit 22 ;;
  esac
done
[[ "$bucket" =~ ^[a-z0-9.-]+$ ]] || exit 22
[[ "$key" =~ ^[A-Za-z0-9_./-]+$ && "$key" != /* && "$key" != *..* ]] || exit 22
destination="$AQARI_MOCK_AWS_ROOT/$bucket/$key"
if [[ "$operation" == "put-object" ]]; then
  [[ "$(printenv AQARI_MOCK_FAIL || true)" != "1" ]] || exit 28
  [[ "$encryption" == "AES256" && "$checksum" == "SHA256" ]] || exit 23
  [[ -s "$body" ]] || exit 24
  if [[ "$key" == *.ok ]]; then
    grep -Eq '^ciphertext_sha256=[0-9a-f]{64}
  [[ ! -e "$destination" ]] || exit 26
  mkdir -p "$(dirname "$destination")"
  cp -- "$body" "$destination"
  printf '{}\n'
elif [[ "$operation" == "head-object" ]]; then
  [[ -f "$destination" ]] || exit 27
  wc -c < "$destination" | tr -d '[:space:]'
  printf '\n'
else
  exit 22
fi
MOCK
chmod 700 "$mock"
export PATH="$root/bin:$PATH"
export AQARI_MOCK_AWS_ROOT="$root/objects"
export AQARI_BACKUP_WORKDIR="$root/work"
export AQARI_BACKUP_BUCKET="aqari-test-private-bucket"
export AQARI_BACKUP_PREFIX="aqari/postgresql"

# Identity is created in CI, never committed to Git.
age-keygen -o "$root/ci-age-key.txt" >/dev/null 2>&1
export AQARI_BACKUP_RECIPIENT="$(age-keygen -y "$root/ci-age-key.txt")"

bash "$repo/backend/scripts/offsite-backup.sh"

ciphertext="$(find "$root/objects" -type f -name '*.tar.age' -print -quit)"
[[ -n "$ciphertext" && -s "$ciphertext" ]] || {
  echo "Missing encrypted off-site object." >&2
  exit 1
}
if find "$root/work" -mindepth 1 -print -quit | grep -q .; then
  echo "Backup left temporary plaintext or encrypted files behind." >&2
  exit 1
fi

age -d -i "$root/ci-age-key.txt" "$ciphertext" | tar -C "$root/decrypted" -xf -
(
  cd "$root/decrypted"
  sha256sum --status --check database.sha256
)
pg_restore --list "$root/decrypted/database.dump" >/dev/null
marker="$ciphertext.ok"
[[ -f "$marker" ]] || { echo "Missing completion marker." >&2; exit 1; }
recorded="$(sed -n 's/^ciphertext_sha256=//p' "$marker")"
actual="$(sha256sum "$ciphertext" | cut -d ' ' -f 1)"
[[ "$recorded" == "$actual" ]] || { echo "Invalid completion hash." >&2; exit 1; }
grep -qx 'attachment_objects_included=no' "$root/decrypted/manifest.txt"
[[ "$(find "$root/objects" -type f | wc -l)" -eq 2 ]]

# Crypto must reject malformed recipients and not upload.
if AQARI_BACKUP_RECIPIENT='not-an-age-recipient' bash "$repo/backend/scripts/offsite-backup.sh" >/dev/null 2>&1; then
  echo "Invalid public recipient unexpectedly accepted." >&2
  exit 1
fi
# Failed destination write must fail and clean up.
if AQARI_MOCK_FAIL=1 bash "$repo/backend/scripts/offsite-backup.sh" >/dev/null 2>&1; then
  echo "Mocked storage outage unexpectedly accepted." >&2
  exit 1
fi
[[ "$(find "$root/objects" -type f | wc -l)" -eq 2 ]] || exit 1
if find "$root/work" -mindepth 1 -print -quit | grep -q .; then
  echo "Rejected encrypted backup left temporary files." >&2
  exit 1
fi

echo "CI age archive, mock S3 upload, integrity, and failure cleanup passed."
 "$body" || exit 25
  else
    head -c 80 "$body" | grep -aq 'age-encryption.org/v1' || exit 25
  fi
  [[ ! -e "$destination" ]] || exit 26
  mkdir -p "$(dirname "$destination")"
  cp -- "$body" "$destination"
  printf '{}\n'
elif [[ "$operation" == "head-object" ]]; then
  [[ -f "$destination" ]] || exit 27
  wc -c < "$destination" | tr -d '[:space:]'
  printf '\n'
else
  exit 22
fi
MOCK
chmod 700 "$mock"
export PATH="$root/bin:$PATH"
export AQARI_MOCK_AWS_ROOT="$root/objects"
export AQARI_BACKUP_WORKDIR="$root/work"
export AQARI_BACKUP_BUCKET="aqari-test-private-bucket"
export AQARI_BACKUP_PREFIX="aqari/postgresql"

# Identity is created in CI, never committed to Git.
age-keygen -o "$root/ci-age-key.txt" >/dev/null 2>&1
export AQARI_BACKUP_RECIPIENT="$(age-keygen -y "$root/ci-age-key.txt")"

bash "$repo/backend/scripts/offsite-backup.sh"

ciphertext="$(find "$root/objects" -type f -name '*.tar.age' -print -quit)"
[[ -n "$ciphertext" && -s "$ciphertext" ]] || {
  echo "Missing encrypted off-site object." >&2
  exit 1
}
if find "$root/work" -mindepth 1 -print -quit | grep -q .; then
  echo "Backup left temporary plaintext or encrypted files behind." >&2
  exit 1
fi

age -d -i "$root/ci-age-key.txt" "$ciphertext" | tar -C "$root/decrypted" -xf -
(
  cd "$root/decrypted"
  sha256sum --status --check database.sha256
)
pg_restore --list "$root/decrypted/database.dump" >/dev/null
grep -qx 'attachment_objects_included=no' "$root/decrypted/manifest.txt"
[[ "$(find "$root/objects" -type f | wc -l)" -eq 1 ]]

# Crypto must reject malformed recipients and not upload.
if AQARI_BACKUP_RECIPIENT='not-an-age-recipient' bash "$repo/backend/scripts/offsite-backup.sh" >/dev/null 2>&1; then
  echo "Invalid public recipient unexpectedly accepted." >&2
  exit 1
fi
# Failed destination write must fail and clean up.
if AQARI_MOCK_FAIL=1 bash "$repo/backend/scripts/offsite-backup.sh" >/dev/null 2>&1; then
  echo "Mocked storage outage unexpectedly accepted." >&2
  exit 1
fi
[[ "$(find "$root/objects" -type f | wc -l)" -eq 1 ]] || exit 1
if find "$root/work" -mindepth 1 -print -quit | grep -q .; then
  echo "Rejected encrypted backup left temporary files." >&2
  exit 1
fi

echo "CI age archive, mock S3 upload, integrity, and failure cleanup passed."
