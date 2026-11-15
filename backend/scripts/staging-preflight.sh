#!/usr/bin/env bash
# Read-only configuration gate; does not provision DNS, certificates, or deploy.
set -euo pipefail

domain="${AQARI_STAGING_DOMAIN:-}"
url="${APP_URL:-}"
email="${AQARI_ACME_EMAIL:-}"
# Require an isolated, non-debug deployment before validating Compose or secrets.
# Keep these checks ahead of Docker access to fail closed on accidental reuse
# of local or production environment files.
if [[ "${APP_ENV:-}" != "staging" ]]; then
  echo "FAIL: APP_ENV must be staging" >&2; exit 1
fi
if [[ "${APP_DEBUG:-}" != "false" ]]; then
  echo "FAIL: APP_DEBUG must be false on staging" >&2; exit 1
fi
if [[ "${DB_CONNECTION:-}" != "mysql" ]]; then
  echo "FAIL: DB_CONNECTION must be mysql on staging" >&2; exit 1
fi
if [[ "${DOCUMENT_SCAN_ENABLED:-}" != "true" ]]; then
  echo "FAIL: DOCUMENT_SCAN_ENABLED must be true on staging" >&2; exit 1
fi
if [[ ! "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] ||
   [[ "$domain" != *.* ]] ||
   [[ "$domain" == *.invalid || "$domain" == *.test || "$domain" == *.example ]] ||
   [[ "$domain" == localhost || "$domain" == *..* ]]; then
  echo "FAIL: Set AQARI_STAGING_DOMAIN to a real dedicated staging hostname" >&2; exit 1
fi
if [[ "$url" != "https://$domain" ]]; then
  echo "FAIL: APP_URL must equal https://AQARI_STAGING_DOMAIN (no trailing slash)" >&2; exit 1
fi
if [[ "$email" != *@*.* ]]; then
  echo "FAIL: Set a valid AQARI_ACME_EMAIL" >&2; exit 1
fi
if [[ "${MAIL_MAILER:-}" != smtp ]]; then
  echo "FAIL: Set MAIL_MAILER=smtp" >&2; exit 1
fi
for name in APP_KEY DB_PASSWORD MYSQL_ROOT_PASSWORD MAIL_HOST MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS; do
  if [[ -z "${!name:-}" ]]; then
    echo "FAIL: Missing environment variable $name" >&2; exit 1
  fi
done
if [[ "${APP_KEY}" == "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=" ]]; then
  echo "FAIL: GitHub Actions test APP_KEY is forbidden on staging" >&2; exit 1
fi
for name in DB_PASSWORD MYSQL_ROOT_PASSWORD MAIL_PASSWORD; do
  case "${!name}" in
    test-only-*|change-me|CHANGE_ME|password|123456)
      echo "FAIL: Placeholder value forbidden for $name" >&2; exit 1;;
  esac
done

# Validate the *effective* merged Compose configuration, not only .env.
# Base compose.yaml intentionally has production defaults; Staging must override them.
if ! command -v python3 >/dev/null 2>&1; then
  echo "FAIL: python3 is required to validate merged staging configuration" >&2; exit 1
fi
docker compose -f compose.yaml -f compose.staging.yaml config --quiet
docker compose -f compose.yaml -f compose.staging.yaml config --format json | python3 -c '
import json, sys
config = json.load(sys.stdin)
services = config.get("services", {})
app = services.get("app", {}).get("environment", {})
db = services.get("db", {}).get("environment", {})
expected_app = {
    "APP_ENV": "staging", "APP_DEBUG": "false",
    "DB_CONNECTION": "mysql", "DB_HOST": "db",
    "DB_DATABASE": "aqari_staging", "DB_USERNAME": "aqari_staging",
    "DOCUMENT_SCAN_ENABLED": "true"
}
expected_db = {"MYSQL_DATABASE": "aqari_staging", "MYSQL_USER": "aqari_staging"}
for key, expected in expected_app.items():
    if str(app.get(key, "")).lower() != expected:
        sys.exit("FAIL: rendered staging app config mismatch: " + key)
for key, expected in expected_db.items():
    if str(db.get(key, "")) != expected:
        sys.exit("FAIL: rendered staging MySQL config mismatch: " + key)
'
echo "PASS: staging origin, isolated Compose DB, non-debug app, upload scanning, SMTP and structure validated"
echo "PENDING: DNS routing, open ports 80/443, ACME certificate issuance and real SMTP delivery"
