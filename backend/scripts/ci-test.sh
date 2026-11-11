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
cp -R "$REPO_ROOT/backend/database/migrations/." "$RUNTIME/database/migrations/"
cp -R "$REPO_ROOT/backend/tests/Feature/." "$RUNTIME/tests/Feature/"
cp "$REPO_ROOT/backend/routes/api.php" "$RUNTIME/routes/api.php"
cp "$REPO_ROOT/backend/routes/web.php" "$RUNTIME/routes/web.php"
cp "$REPO_ROOT/backend/bootstrap/app.php" "$RUNTIME/bootstrap/app.php"

(
  cd "$RUNTIME"
  php artisan migrate --force --no-interaction
  php artisan route:list --path=api
  php artisan test --filter='(PropertyIsolationTest|PortfolioIsolationTest|ContractCreationTest|PaymentPostingTest|ReceivablesReversalTest|SessionAuthenticationTest|PrivateAttachmentsTest)'
)

echo "Laravel 12 owner-isolation integration suite finished."
