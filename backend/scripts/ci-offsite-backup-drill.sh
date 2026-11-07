#!/usr/bin/env bash
# Deterministic test of offsite backup transfer verification: no network/Docker.
set -euo pipefail

root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT
mkdir -p "$root/scripts" "$root/bin" "$root/remote"
cp "$(dirname "$0")/backup-offsite.sh" "$root/scripts/backup-offsite.sh"

cat > "$root/scripts/backup-encrypted.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'encrypted database test content\n' > "$BACKUP_DIR/aqari-db-ci.sql.gz.age"
printf 'encrypted private files test content\n' > "$BACKUP_DIR/aqari-files-ci.tar.gz.age"
EOF

cat > "$root/bin/rclone" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
  listremotes) printf 'offsite:\n' ;;
  copy)
    source="$2"
    cp "$source"/*.age "$RCLONE_FAKE_STORE/"
    if [[ "${RCLONE_FAKE_CORRUPT:-}" == "1" ]]; then
      printf 'intentionally damaged\n' > "$RCLONE_FAKE_STORE/aqari-db-ci.sql.gz.age"
    fi
    ;;
  lsf)
    find "$RCLONE_FAKE_STORE" -maxdepth 1 -type f -printf '%f\n'
    ;;
  check)
    for file in "$2"/*.age; do
      cmp -s "$file" "$RCLONE_FAKE_STORE/$(basename "$file")" || exit 1
    done
    ;;
  *) echo "Unexpected mocked rclone command: $1" >&2; exit 2 ;;
esac
EOF
chmod +x "$root/bin/rclone"
for bin in age docker; do printf '#!/bin/sh\nexit 0\n' > "$root/bin/$bin"; chmod +x "$root/bin/$bin"; done

export PATH="$root/bin:$PATH"
export RCLONE_FAKE_STORE="$root/remote"
export AGE_RECIPIENT="age1dummy-ci-public-recipient"
export AQARI_BACKUP_REMOTE="offsite:staging-backup"
export AQARI_BACKUP_APPROVED="encrypted-offsite"
export AQARI_BACKUP_LOCK="$root/backup.lock"

(
  cd "$root"
  bash scripts/backup-offsite.sh
)
test -s "$root/remote/aqari-db-ci.sql.gz.age"
test -s "$root/remote/aqari-files-ci.tar.gz.age"

export RCLONE_FAKE_CORRUPT=1
if ( cd "$root" && bash scripts/backup-offsite.sh >/dev/null 2>&1 ); then
  echo "FAIL: offsite script reported success after remote content corruption" >&2
  exit 1
fi
echo "PASS: offsite upload verifies encrypted contents and detects remote corruption"
