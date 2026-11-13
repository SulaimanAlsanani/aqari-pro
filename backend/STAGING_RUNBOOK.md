# Isolated Aqari Pro staging runbook

This is a **separate stack**, not an overlay on `compose.yaml` or `compose.staging.yaml`. The standalone file is `compose.staging-isolated.yaml`, with Compose project name `aqari-pro-staging`. MySQL has no published ports. Apache is bound only to `127.0.0.1:18080`; an independently controlled HTTPS reverse proxy must be configured before browser acceptance tests.

## Before startup

1. Use a staging-only host and isolated DNS name, SMTP account, S3 bucket, APP_KEY, BACKUP_ENCRYPTION_KEY and database passwords. Do not reuse production secrets, data volumes or backup bucket.
2. Create `/etc/aqari-pro-staging/secrets.env` **outside the Git checkout and Docker build context**, mode `0600`, owned by the operator. Populate at least:
   `STAGING_APP_KEY`, `STAGING_APP_URL` (HTTPS), `STAGING_DB_PASSWORD`, `STAGING_MYSQL_ROOT_PASSWORD`, `STAGING_MAIL_HOST`, `STAGING_MAIL_USERNAME`, `STAGING_MAIL_PASSWORD`, `STAGING_MAIL_FROM_ADDRESS`, `STAGING_BACKUP_S3_KEY`, `STAGING_BACKUP_S3_SECRET`, `STAGING_BACKUP_S3_BUCKET`, `STAGING_BACKUP_ENCRYPTION_KEY`. Optional: `STAGING_MAIL_PORT`, `STAGING_BACKUP_S3_REGION`, `STAGING_BACKUP_S3_ENDPOINT`. The staging S3 bucket name must contain a distinct `stage` or `staging` segment (for example `aqari-staging-backups`) to pass the preflight guard. Never commit or log these values.
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
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml exec -T app php artisan staging:preflight --live
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml exec -T app php artisan migrate:status
curl --fail --silent --show-error http://127.0.0.1:18080/up
```

**Important:** `migrate:status` is read-only. Never run an automatic database migration against a restored dataset until an operator verifies that this is the correct isolated instance and approves any schema changes. Do not point PHPUnit/RefreshDatabase tests at a real restored database because tests may drop tables.

The first-time isolated empty staging database requires an explicit, reviewed `php artisan migrate --force` before meaningful API tests. Use only newly created staging data for test owner accounts. Backups and restore drills are separate manual operations and should not be executed implicitly during container startup.

## Browser and recovery acceptance

After the reverse proxy and certificate are verified, test login/MFA, property and unit lists, lease schedules, collection/receipt idempotency, cashflow, tenant statements, secure document upload/download, and cross-owner authorization. Run the isolated MySQL restore drill from a verified staged encrypted backup on a separate rehearsal host; do not import backup SQL into this live staging app automatically.

The staging stack is not production-ready by itself: CI must pass, SMTP delivery and ClamAV scanning must be proven, private S3 access tested, monitoring/retention added, and a real end-to-end restore rehearsal completed. The Compose `config` command can reveal interpolated secrets; use `--quiet` on operator machines and do not publish rendered configuration or shell history.

## Live readiness safeguards

The optional `staging:preflight --live` checks **read-only** MySQL connectivity using `SELECT 1` and ensures a local ClamAV executable and both main/daily signature databases are present. It **will not open a database connection if any static staging isolation rule fails**. This avoids trusting a misconfigured database endpoint simply because the command was invoked. The check does not execute a malware scan or prove signatures are current; inspect `freshclam` update logs and perform a safe scanner test separately.

After the live preflight, conduct `backup:check-storage` with staging-specific S3 credentials and verify SMTP delivery without sending production messages. Both are separate operational gates; neither is performed by the preflight. Record the outcome of each gate in the deployment checklist, without recording credentials. Do not proceed to application/browser acceptance until these checks pass and the staging HTTPS reverse proxy is independently verified.

## Gated live service checks (staging only)

After `staging:preflight --live` passes, run the new command on the isolated staging host:

```bash
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml \
  exec -T app php artisan staging:check-services
```

This command first re-runs the live staging configuration, read-only MySQL and ClamAV-signature checks. **Only if these succeed** does it call `backup:check-storage` to upload, read, verify and remove a random 64-byte object in the *staging* S3-compatible bucket. If any prerequisite fails, no S3 or SMTP action is attempted. It does not access live owner documents or perform a database restore.

To explicitly test SMTP submission to an operator-controlled **staging test mailbox**:

```bash
docker compose --env-file "$STAGING_ENV_FILE" -f compose.staging-isolated.yaml \
  exec -T app php artisan staging:check-services --mail-to=test-inbox@example.test
```

Replace the example mailbox with an actual authorized test inbox before execution. This sends a real generic test email **only when the option is supplied** and only after the MySQL/ClamAV and private S3 checks pass. A successful SMTP call means the configured transport accepted the message; the operator must still confirm inbox delivery. No customer data is included.

The command refuses non-staging environments and invalid recipient addresses, and requires an SMTP mailer before attempting remote probes when a recipient is supplied. Never use production SMTP credentials or the production S3 bucket for staging. The command has no automated schedule, and is not a substitute for an encrypted-backup recovery drill, email deliverability monitoring or HTTPS browser tests.

## Read-only browser acceptance

The new `browser-acceptance/` suite uses Playwright to check the actual server-hosted owner portal after the HTTPS proxy, MySQL, ClamAV and S3 checks succeed. Read `browser-acceptance/README.md` before running. It requires a matched, explicitly allowlisted staging HTTPS hostname and (for the owner journey) a dedicated synthetic owner account.

Execute `npm run check` first to validate JavaScript and the production-target safety guard; then `npm test` on the authorized staging host. It verifies login, dashboard, property/unit/tenant/lease/due/payment/document lists, cashflow and logout without creating financial records. Browser traces, screenshots and videos are disabled. **The GitHub CI job performs offline syntax/guard checks only**; it does not perform a live browser acceptance test, and missing test credentials cause the authenticated browser journey to skip.

## Opt-in synthetic browser write acceptance

The `browser-acceptance/tests/owner-write.spec.cjs` scenario can exercise the actual owner UI to create a synthetic property, unit, tenant, lease and receipt, then upload/download a synthetic document and validate due balances. **It is disabled by default** and never executes in the normal GitHub Actions checks.

Only enable it on a disposable isolated staging database with a new test owner: set `STAGING_E2E_WRITE_ENABLED=true` and `STAGING_E2E_OWNER_EMAIL=aqari-e2e-<unique-id>@your-test-domain` in the private staging stack secrets; securely inject matching `STAGING_TEST_OWNER_EMAIL` and `STAGING_TEST_OWNER_PASSWORD` into the browser runner. Set `STAGING_E2E_WRITE_CONFIRMATION=ALLOW_ONE_TIME_SYNTHETIC_WRITES` **only after reviewing the target host**, then run `npx playwright test tests/owner-write.spec.cjs`. See `browser-acceptance/README.md` for all prerequisites.

The authenticated server-side `/api/staging/e2e-readiness` denies access unless the deployment is staging, targets the expected MySQL service and staging URL, uses the exact synthetic account, and the account has no existing records. After the first successful or partially completed run, the account will be blocked on subsequent runs. Do not reset by deleting financial records through the application; reprovision a fresh disposable database or test owner under operator supervision. Disable the server's write-test opt-in immediately after completion.
