#!/bin/sh
set -eu
if [ "${APP_ENV:-production}" = "production" ]; then
  case "${APP_URL:-}" in
    https://*) ;;
    *) echo "APP_URL must use HTTPS for production" >&2; exit 1 ;;
  esac
  if [ "${MAIL_MAILER:-}" != "smtp" ]; then
    echo "Production password recovery requires MAIL_MAILER=smtp" >&2; exit 1
  fi
fi
mkdir -p /var/www/html/storage/app/private /var/www/html/storage/framework/cache/data \
  /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views \
  /var/www/html/storage/logs /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
exec docker-php-entrypoint "$@"
