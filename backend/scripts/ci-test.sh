#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNTIME="${RUNNER_TEMP:-/tmp}/aqari-laravel-ci"

if [[ -e "$RUNTIME" ]]; then
  echo "Refusing to overwrite preexisting runtime: $RUNTIME" >&2
  exit 1
fi

composer create-project laravel/laravel "$RUNTIME" "^12.0" --no-interaction --prefer-dist
(
  cd "$RUNTIME"
  composer require "laravel/sanctum:^4.0" --no-interaction --prefer-dist
)

cp -R "$REPO_ROOT/backend/app/." "$RUNTIME/app/"
cp -R "$REPO_ROOT/backend/config/." "$RUNTIME/config/"
cp -R "$REPO_ROOT/backend/database/migrations/." "$RUNTIME/database/migrations/"
cp -R "$REPO_ROOT/backend/tests/Feature/." "$RUNTIME/tests/Feature/"
cp "$REPO_ROOT/backend/routes/api.php" "$RUNTIME/routes/api.php"
cp "$REPO_ROOT/backend/routes/web.php" "$RUNTIME/routes/web.php"
cp "$REPO_ROOT/backend/bootstrap/app.php" "$RUNTIME/bootstrap/app.php"

(
  cd "$RUNTIME"
  php artisan migrate --force --no-interaction
  php artisan route:list --path=api
  php artisan test --filter='(PropertyIsolationTest|PortfolioIsolationTest|ContractCreationTest|PaymentPostingTest|ReceivablesReversalTest|SessionAuthenticationTest|PrivateAttachmentsTest|AttachmentStorageVerificationTest)'
)

echo "Rehearsing PostgreSQL backup and restore in a fresh isolated database..."
BACKUP_DIRECTORY="$(dirname "$RUNTIME")/aqari-recovery-ci"
bash "$REPO_ROOT/backend/scripts/pg-backup.sh" "$BACKUP_DIRECTORY"

# The CI PostgreSQL account owns this ephemeral service and may provision a
# disposable rehearsal database. Production scripts never create/drop databases.
PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USERNAME" \
PGPASSWORD="$DB_PASSWORD" psql -X -v ON_ERROR_STOP=1 --dbname=postgres \
  -c 'CREATE DATABASE aqari_restore_ci'

bash "$REPO_ROOT/backend/scripts/pg-restore-verify.sh" \
  "$BACKUP_DIRECTORY" aqari_restore_ci

# A modified archive must be rejected before touching any target database.
cp -a "$BACKUP_DIRECTORY" "$BACKUP_DIRECTORY-tampered"
printf 'x' >> "$BACKUP_DIRECTORY-tampered/database.dump"
if bash "$REPO_ROOT/backend/scripts/pg-restore-verify.sh" \
  "$BACKUP_DIRECTORY-tampered" aqari_restore_tamper; then
  echo "ERROR: tampered backup unexpectedly passed verification." >&2
  exit 1
fi

echo "PostgreSQL isolated restore rehearsal and tamper rejection passed."

echo "Laravel 12 owner-isolation integration suite finished."
