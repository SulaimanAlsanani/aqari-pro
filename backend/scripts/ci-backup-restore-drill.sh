#!/usr/bin/env bash
# CI-only encrypted backup and isolated restore exercise. Never targets production.
set -euo pipefail

if [[ "${CI:-}" != "true" || "${GITHUB_ACTIONS:-}" != "true" ]]; then
  echo "This destructive-seeding test can run only on a disposable GitHub Actions environment." >&2
  exit 1
fi

command -v age-keygen >/dev/null
command -v age >/dev/null
command -v docker >/dev/null
command -v tar >/dev/null

WORK="$(mktemp -d)"
RESTORE_DB="aqari_restore_drill"
cleanup() {
  docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS aqari_restore_drill"' >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

# A marker tests actual MySQL row restoration, not merely decompression.
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u aqari aqari -e "CREATE TABLE backup_restore_probe (id INT PRIMARY KEY, note VARCHAR(100) NOT NULL); INSERT INTO backup_restore_probe VALUES (1, '\''BACKUP_RESTORE_VERIFIED'\'');"'
docker compose exec -T app sh -c "printf 'PRIVATE_ATTACHMENT_RESTORED\\n' > /var/www/html/storage/app/private/ci-backup-proof.txt"

age-keygen -o "$WORK/identity.txt" > "$WORK/age-keygen.log" 2>&1
AGE_RECIPIENT="$(age-keygen -y "$WORK/identity.txt")" \
BACKUP_DIR="$WORK/snapshots" bash scripts/backup-encrypted.sh

DB_FILE="$(find "$WORK/snapshots" -maxdepth 1 -name 'aqari-db-*.sql.gz.age' -print -quit)"
DOC_FILE="$(find "$WORK/snapshots" -maxdepth 1 -name 'aqari-files-*.tar.gz.age' -print -quit)"
test -s "$DB_FILE"
test -s "$DOC_FILE"

docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE aqari_restore_drill"'
age -d -i "$WORK/identity.txt" "$DB_FILE" | gzip -dc |
  docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot aqari_restore_drill'

RESTORED="$(docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -s aqari_restore_drill -e "SELECT note FROM backup_restore_probe WHERE id=1"')"
test "$RESTORED" = "BACKUP_RESTORE_VERIFIED"

mkdir -p "$WORK/attachment-restore"
age -d -i "$WORK/identity.txt" "$DOC_FILE" | tar -xz -C "$WORK/attachment-restore"
test "$(cat "$WORK/attachment-restore/app/private/ci-backup-proof.txt")" = "PRIVATE_ATTACHMENT_RESTORED"

# Verify the application exits maintenance mode even after backup.
HTTP_STATUS="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/up)"
test "$HTTP_STATUS" = "200"
echo "PASS: encrypted database and private documents recovered in isolation."
