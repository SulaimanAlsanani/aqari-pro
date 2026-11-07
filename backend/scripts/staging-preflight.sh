#!/usr/bin/env bash
# Read-only configuration gate; does not provision DNS, certificates, or deploy.
set -euo pipefail

domain="${AQARI_STAGING_DOMAIN:-}"
url="${APP_URL:-}"
email="${AQARI_ACME_EMAIL:-}"
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

docker compose -f compose.yaml -f compose.staging.yaml config --quiet
echo "PASS: staging origin, ACME email, SMTP and compose structure validated"
echo "PENDING: DNS routing, open ports 80/443, ACME certificate issuance and real SMTP delivery"
