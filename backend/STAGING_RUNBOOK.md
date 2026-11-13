# Isolated Aqari Pro staging runbook

This is a **separate stack**, not an overlay on `compose.yaml` or `compose.staging.yaml`. The standalone file is `compose.staging-isolated.yaml`, with Compose project name `aqari-pro-staging`. MySQL has no published ports. Apache is bound only to `127.0.0.1:18080`; an independently controlled HTTPS reverse proxy must be configured before browser acceptance tests.

## Before startup

1. Use a staging-only host and isolated DNS name, SMTP account, S3 bucket, APP_KEY, BACKUP_ENCRYPTION_KEY and database passwords. Do not reuse production secrets, data volumes or backup bucket.
2. Create `/etc/aqari-pro-staging/secrets.env` **outside the Git checkout and Docker build context**, mode `0600`, owned by the operator. Populate at least:
   `STAGING_APP_KEY`, `STAGING_APP_URL` (HTTPS), `STAGING_DB_PASSWORD`, `STAGING_MYSQL_ROOT_PASSWORD`, `STAGING_MAIL_HOST`, `STAGING_MAIL_USERNAME`, `STAGING_MAIL_PASSWORD`, `STAGING_MAIL_FROM_ADDRESS`, `STAGING_BACKUP_S3_KEY`, `STAGING_BACKUP_S3_SECRET`, `STAGING_BACKUP_S3_BUCKET`, `STAGING_BACKUP_ENCRYPTION_KEY`. Optional: `STAGING_MAIL_PORT`, `STAGING_BACKUP_S3_REGION`, `STAGING_BACKUP_S3_ENDPOINT`. Never commit or log these values.
3. Verify ClamAV signatures can be updated on the staging host. The image contains ClamAV, but the daemon/signature update mechanism and actual scanning must be checked separately. The config preflight only checks that scanning is enabled.
4. Confirm MySQL/SMTP/S3 outbound firewall rules, disk capacity and data-retention settings. The app container needs SMTP/S3 egress; MySQL is confined to an internal network.

## Boot and inspect

From `backend/` on the staging host:

```bash
export STAGING_ENV_FILE=/etc/aqari-pro-staging/secrets.env
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml config --quiet
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml up -d --build
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml ps
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml exec -T app php artisan staging:preflight
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml exec -T app php artisan migrate:status
curl --fail --silent --show-error http://127.0.0.1:18080/up
```

**Important:** `migrate:status` is read-only. Never run an automatic database migration against a restored dataset until an operator verifies that this is the correct isolated instance and approves any schema changes. Do not point PHPUnit/RefreshDatabase tests at a real restored database because tests may drop tables.

The first-time isolated empty staging database requires an explicit, reviewed `php artisan migrate --force` before meaningful API tests. Use only newly created staging data for test owner accounts. Backups and restore drills are separate manual operations and should not be executed implicitly during container startup.

## Browser and recovery acceptance

After the reverse proxy and certificate are verified, test login/MFA, property and unit lists, lease schedules, collection/receipt idempotency, cashflow, tenant statements, secure document upload/download, and cross-owner authorization. Run the isolated MySQL restore drill from a verified staged encrypted backup on a separate rehearsal host; do not import backup SQL into this live staging app automatically.

The staging stack is not production-ready by itself: CI must pass, SMTP delivery and ClamAV scanning must be proven, private S3 access tested, monitoring/retention added, and a real end-to-end restore rehearsal completed. The Compose `config` command can reveal interpolated secrets; use `--quiet` on operator machines and do not publish rendered configuration or shell history.
