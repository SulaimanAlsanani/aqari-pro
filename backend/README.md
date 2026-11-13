# Aqari Pro — production API foundation

This is a **development-stage Laravel 12 API**, intended to replace browser-only storage in Aqari Pro V1 for an individual property owner.

## Local setup
1. PHP 8.3+, Composer, SQLite or MySQL, PHP extensions mbstring, fileinfo, PDO SQLite/MySQL and bcmath.
2. `cd backend && composer install`
3. `cp .env.example .env && php artisan key:generate`
4. Create `database/database.sqlite` when using SQLite, then `php artisan migrate`.
5. Provision the initial owner interactively with `php artisan owner:create owner@example.com 'اسم المالك'` on the server (no public registration endpoint, no password in shell history).
6. `php artisan serve`
7. `php artisan test`.

## واجهة المالك المتصلة فعليًا
- بعد تشغيل Laravel وتهيئة حساب مالك، افتح `http://localhost:8000/app/index.html`.
- الواجهة تستخدم `/api` على نفس الأصل لتجنب مشاركة الرموز عبر جهات أخرى، وتحتفظ برمز الدخول في ذاكرة الصفحة فقط.
- تشمل لوحة المؤشرات، والقوائم المحمية، والنماذج الأساسية، ورفع المرفقات وتنزيلها.
- ما تزال قيد التطوير: عمليات التعديل والحذف والتصديرات والإشعارات والحسابات التفصيلية.
- موقع GitHub Pages العام **لا يستخدم هذه الواجهة الجديدة بعد**؛ لا ترفع عليه مستندات حقيقية.

## API
- `POST /api/login` issues a short-lived Sanctum Bearer token.
- Protected: `GET /api/me`, `POST /api/logout`.
- `GET /api/portfolio` and `GET /api/overview` show owner-scoped information.
- Properties, units, tenants, leases, payments, due schedules, expenses and maintenance.
- `POST /api/documents` stores private uploaded files with ownership checks, file type and size restrictions.
- `GET /api/documents/{id}` and `DELETE /api/documents/{id}` are owner-authorized.

## Security invariants
- All domain queries are restricted to the authenticated owner.
- A property, unit, tenant, lease or payment ID owned by another user must return 404.
- Document uploads live on the private disk and require explicit ownership validation.
- Public self-registration is disabled.
- Every deployment must use HTTPS, `APP_DEBUG=false`, proper server secrets, backup policies, monitoring and secret management.
- GitHub Pages remains **demo-only** and is not connected to this API.

## Not yet launch-ready
Frontend authentication and API integration, robust password reset / account recovery, audit history, invoice and receipt workflows, notifications, encrypted backup/restore, admin provisioning, operations documentation, secure hosting, security review and end-to-end testing are still required.

## Owner multi-factor authentication (development branch)
- Authenticator TOTP (SHA-1 / 30 seconds / 6 digits) is supported through `/api/account/mfa` and `/api/account/mfa/setup`, `/confirm`, `/disable`.
- After verifying the password, login responds `428` and `mfa_required: true` when a second factor is missing. Complete login with `mfa_code` (an authenticator code or a 16-character recovery code).
- Activating MFA displays eight single-use recovery codes exactly once. Save these offline; only SHA-256 hashes remain in the database.
- TOTP secrets are encrypted via Laravel's `encrypted` Eloquent casts. Protect `APP_KEY` in secret management; if it is lost, encrypted MFA secrets are unrecoverable.
- Setup and disable require the current account password. Activating or disabling MFA revokes all existing Sanctum tokens. Successful TOTP verification prevents time-step replay, and recovery-code consumption is serialized by a user-row lock.
- The server-hosted owner portal (`backend/public/app/`) now includes the login challenge and MFA settings in **حسابي**. The public GitHub Pages demo intentionally does not provide these features.
- Before considering production: execute migrations and the MFA CI checks; verify HTTPS, secure key management, application clocks, recovery processes, and review MFA security with real browser tests. The workflow code is present, but a green result is required.

## Owner session management (development branch)
- `GET /api/account/sessions` lists up to 100 unexpired Sanctum bearer sessions belonging only to the authenticated owner, marking the current token.
- `DELETE /api/account/sessions/{token}` revokes an individual owner-owned token; `DELETE /api/account/sessions/others` revokes all other tokens while preserving the present one.
- Both revoke operations require the current account password and are rate limited. Revoking the current token requires logging in again.
- The portal exposes session management from **حسابي**. Token creation, last use and expiry are visible; they are *sessions*, not verified hardware/device identities.
- Security tests include cross-owner isolation, current-token revocation, and revocation of all other sessions.

## MFA setup-expiration safeguard
- Pending authenticator enrollment expires 10 minutes after it is created. Expired pending secrets are invalidated; the owner must begin setup again.
- During confirmation, the server records the exact accepted TOTP time step (including a tolerated adjacent step) so that it cannot be reused for login.
- Apply both `2026_11_12_120000_add_mfa_to_users_table.php` and `2026_11_12_130000_add_mfa_pending_expiry_to_users_table.php` migrations before enabling MFA. These changes are not a replacement for a green CI run and staging verification.

## Private object-storage preflight (development branch)
A dedicated private `backup` filesystem disk now supports S3-compatible providers. Configure `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`, and optional `BACKUP_S3_ENDPOINT` and `BACKUP_S3_PATH_STYLE` as environment secrets. Install PHP packages through Composer on the deployment server.

Run `php artisan config:clear && php artisan backup:check-storage` on a locked-down staging server. The command rejects local/public storage, uploads 64 random bytes under a unique `healthchecks/` object name, reads back and hashes the object, then deletes it. It must exit successfully before backup infrastructure is considered connected. Test code uses a fake disk and **does not establish access to a real external bucket**.

**This preflight is not a backup**: it does not export a database, copy private attachments, test restore, enforce retention/immutability, or monitor backup age. Do not claim disaster-recovery readiness until an encrypted, consistent database-and-attachments backup and an isolated restore drill have been implemented and observed. Use a provider-managed private bucket, least-privilege credentials, versioning and immutable retention where supported. Never use the public GitHub Pages demo to store real property documents.

## Encrypted small-installation snapshot prototype
The `php artisan backup:make` command is deliberately constrained: it supports **SQLite and offline MySQL 8**, requires the application to already be in **maintenance mode**, and refuses archives larger than 128 MiB. It uses SQLite `VACUUM INTO` for a consistent database snapshot, packages private `owners/*/documents/*` attachments plus SHA-256 manifest in ZIP, validates all archived checksums and paths, then encrypts the archive with AES-256-GCM under a **dedicated BACKUP_ENCRYPTION_KEY** (base64 of 32 random bytes). The encrypted object is uploaded privately to the configured S3-compatible `backup` disk, downloaded, compared by digest, and checked for authenticated decryption. Working files are deleted after the command.

**Prerequisites:** PHP extensions openssl and zip, S3 adapter installed by Composer, a correctly configured private backup bucket, separate encryption-key escrow, available disk space and configured `BACKUP_ENCRYPTION_KEY`. On staging only, enter maintenance mode with `php artisan down`, run `php artisan backup:check-storage` then `php artisan backup:make`, and restore service with `php artisan up` after verifying results. This command does not automatically manage maintenance mode.

**Limitations / launch blockers:** This is not production-grade disaster recovery. The MySQL path requires an external write barrier and InnoDB tables; the snapshot operates in memory up to 128 MiB; attachment changes by external writers during maintenance could affect consistency; a full restore command, independent isolated restore drill, managed scheduled backups, retention enforcement, encryption-key rotation and operational monitoring remain outstanding. Never restore an unverified archive over a live database.

## Read-only backup verification and isolated restore staging

For an existing encrypted backup object with a full path returned by `backup:make`:
- `php artisan backup:verify snapshots/YYYY/MM/DD/HHMMSS-UUID.aqbk` authenticates and decrypts the remote object into an ephemeral private directory, verifies all ZIP entries against the SHA-256 manifest, and removes its temporary copy. No live database or attachments are touched.
- `php artisan backup:stage-restore snapshots/YYYY/MM/DD/HHMMSS-UUID.aqbk` performs the same envelope and archive validation, then writes individual allowlisted files to a **new random directory under `storage/app/restore-staging/`** and runs `PRAGMA integrity_check` against the recovered SQLite database. It never replaces live data and does not update application configuration. Restrict access to this directory (sensitive data is decrypted during the drill). Delete it securely after validation.

Both commands require the independent backup key, a private S3 disk, and PHP ZIP support. They enforce the 128 MiB encrypted backup-size limit and reject unexpected archive members, missing checksums, oversized expansion and malformed object keys. Run staged restore only on an isolated non-production host with separate credentials.

**Not yet an automatic in-place recovery path:** service recovery still requires an approved maintenance procedure, a separate database and attachment reconciliation, verification of application startup, a restore rehearsal, and retention/monitoring controls. Do not use the staged data in production directly. MySQL import is supported only in a disposable isolated Docker drill, not against production.

## Backup freshness monitoring (opt-in)
Run `php artisan backup:status --disk=backup --max-age-hours=26` to scan recent private S3 snapshot partitions and return a nonzero exit code if the last valid-looking object is missing or stale. This is a read-only freshness check; it does **not** authenticate/decrypt a snapshot, prove successful restore, or confirm business data consistency. Run `backup:verify` and a separate isolated restore rehearsal for integrity assurance.

Set `BACKUP_MONITOR_ENABLED=true` and `BACKUP_MAX_AGE_HOURS=26` to register an hourly Laravel scheduler command. The deployment must configure the normal Laravel scheduler (`* * * * * php artisan schedule:run`) and durable shared cache locks when using `onOneServer`. Ensure an external monitoring service alerts on failed scheduled commands; no notifications are automatically delivered by this command. **No automatic backup creation is enabled** because `backup:make` requires maintenance mode, and pausing an active system unattended would be unsafe. Configure maintenance and backups only after an operational runbook and isolated restore tests pass.

## Backup incident logging and opt-in alerting

Every `backup:status` check records a structured server log entry with an allowlisted state: `fresh`, `stale`, `unknown`, or `invalid_configuration`. Events intentionally omit backup object keys, remote credentials, secret keys, user data, and exception details. Inspect the protected application logs to diagnose errors.

Optional SMTP notification of non-fresh states: set `BACKUP_ALERT_ENABLED=true`, `BACKUP_ALERT_EMAIL=ops@example.com`, and `BACKUP_ALERT_COOLDOWN_MINUTES=360`. A valid recipient and a non-`log`/`array` mail transport are mandatory before sending. Back-to-back failures are suppressed for the configured cooldown by `Cache::add`; use a persistent shared cache on multi-server deployments. The alert contains only a generic status and instructions to inspect server logs.

These notifications are **not delivery guarantees**. Email submission can fail, email may be blocked, and a broken scheduler will not invoke `backup:status` at all. Wire independent external uptime monitoring to detect scheduler inactivity and verify alert delivery in staging. This does not schedule snapshot creation or grant disaster-recovery readiness.

## MySQL 8 offline snapshot support (development)
The existing `backup:make` encrypted backup flow now supports either SQLite or MySQL 8. For MySQL it uses `mysqldump` with `--single-transaction --quick --routines --triggers --events --hex-blob`, refuses non-InnoDB base tables, and writes the export as `database.mysql.sql` into the same signed-by-GCM ZIP/manifest pipeline as private attachments. The encrypted object is uploaded to private S3-compatible storage as before.

**Required before running on staging:** install and verify the compatible `mysqldump` executable and PDO MySQL extension, configure discrete MySQL connection values (not DB_URL), configure private bucket and BACKUP_ENCRYPTION_KEY, and ensure the database and attachment storage are truly quiescent. The operator must enter maintenance mode and stop workers, inbound webhooks, direct SQL clients, external writers, and file processors; Laravel maintenance mode alone does not stop them. Only InnoDB base tables are accepted. The temporary MySQL client options file is mode 0600 and is removed after the dump; credentials are not passed in process arguments or shell commands. This backup remains size-limited to 128 MiB and is not suitable for large databases.

`backup:verify` can authenticate and check either database format. **`backup:stage-restore` now safely extracts MySQL SQL archives into an isolated private directory without executing them.** MySQL restore support, full-database integration tests, coordinated pause of external writers, staged restore exercises, secure archival retention and observed CI green status are still required. Never assume this constitutes an operational MySQL disaster recovery service.

## MySQL restore staging safety (development)

`php artisan backup:stage-restore snapshots/YYYY/MM/DD/HHMMSS-UUID.aqbk` now accepts encrypted MySQL snapshots with a valid manifest, and stages `database.mysql.sql` plus allowlisted attachments under a random private path. **It never executes the dump** or connects to a MySQL destination. The MySQL dump was produced with `mysqldump --databases`, which may contain `CREATE DATABASE` and `USE` statements and is therefore unsafe to replay blindly even when pointed at a purported test connection.

An actual isolated MySQL restore still requires a separate disposable MySQL instance, a restore-only account with no production connectivity, deliberate database-name remapping, controlled import, and post-import application checks. Staging the archive on the same host is not in itself an isolated database restore. Restrict filesystem access and remove staged plaintext files after review.

## Fully disposable MySQL restore drill (offline Docker)

To test importing a verified MySQL backup without touching the production database:

1. On a **dedicated staging machine** with local Docker Engine and Python 3.10+, run `php artisan backup:stage-restore snapshots/YYYY/MM/DD/HHMMSS-UUID.aqbk`. Record the private UUID folder printed on success.
2. Invoke `python3 scripts/mysql_isolated_restore.py --stage /absolute/path/to/backend/storage/app/restore-staging/UUID --app-storage /absolute/path/to/backend/storage/app`. Use `--validate-only` to check path constraints without starting Docker.
3. The script starts a throwaway `mysql:8.4` container with **`--network none`**, no published ports, no bind mounts, and an ephemeral tmpfs database. It imports the staged SQL using random drill-only root credentials, runs `mysqlcheck`, counts restored application base tables, and removes the container on exit. Original SQL statements such as `CREATE DATABASE` and `USE` are safe only *inside this disposable container*.
4. Remove the staged plaintext directory after the drill, following your organization’s secure cleanup policy. Never make it web-accessible.

GitHub Actions now includes a separate `mysql-isolated-restore` job exercising this workflow with a synthetic fixture and the actual Docker container, as well as Python safety unit tests. **A green run is still required**; a committed workflow is not a completed integration test. The script rejects nonlocal Docker contexts and source paths outside `restore-staging/UUID`. It does not receive Laravel DB settings or production credentials.

**Limitations:** Docker-based import alone is not a full application restore or a demonstration of attachment consistency, data equivalence, recovery point objective (RPO) or recovery time objective (RTO). A real encrypted S3 backup, the associated attachments and the application must be validated in a separate operational rehearsal before launch.

## Backup attachment-to-database reconciliation

Before writing any ZIP, `backup:make` now runs `BackupAttachmentAudit::verify()` against the `documents` table and the private `local` disk. It rejects documents with invalid or cross-owner paths, duplicate paths, missing files or mismatched sizes. It also rejects untracked files in `owners/*` so the encrypted backup cannot silently include unknown documents or omit registered ones. A mismatch **fails the entire backup**; it is not auto-corrected or silently ignored. Resolve discrepancies with a reviewed incident procedure and never delete records merely to make a backup green.

This audit requires all writers to be stopped, including background workers and direct uploads. It checks file presence and size, **not content hash equivalence with the database** (no persisted content digest exists), and does not yet verify every restored document against a separately restored database. The existing ZIP manifest still checks SHA-256 of every included file. Tests use a fake filesystem and SQLite database; a real end-to-end staging drill is required.

## Post-extraction recovery integrity check
After authenticating and validating an encrypted ZIP, `backup:stage-restore` also runs `BackupStagedFilesVerifier::verify()` against the newly extracted files, requiring each database file and attachment to exactly match its recorded byte count and SHA-256 digest. A truncated, altered or missing output fails the staged restore; the temporary directory is removed on failure. This does **not** yet compare the recovered attachments to the actual recovered MySQL `documents` rows, since the SQL import is run separately inside a disposable Docker environment. Such a post-import relational comparison remains a launch gate.

## MySQL recovery drill: post-import document reconciliation
The disposable MySQL restore drill now reads the recovered application's `documents` table from the isolated container (it requires exactly one non-system schema containing that table). It compares each restored `user_id`, private `path`, and recorded `size` against the staged `attachments/owners/` tree. Missing, unexpected, duplicated, cross-owner and size-mismatched attachments fail the drill. Path values are transferred as hex-encoded SQL output so unusual characters cannot break the tabular protocol. The tool never sends attachment contents or production credentials into the container.

The CI fixture now includes a restored `documents` record and matching staged private attachment. Python safety tests cover matching, missing, orphaned, cross-owner and size-mismatched files. These checks complement ZIP file hashes, but **do not prove** document content hashes match database rows, because the current `documents` schema does not store hashes. The entire path requires an actual green CI run and independent staging rehearsal with a real encrypted backup before production approval.

## Restored property data integrity gates
The disposable MySQL recovery drill now verifies that the recovered schema includes `users`, `properties`, `units`, `tenants`, `leases`, `payments`, and `dues`. It fails on missing tables, orphan property/tenant ownership, missing property or tenant for a lease, missing lease for a payment or due, and leases whose tenant owner differs from the linked property owner. These are additional application-level checks beyond `mysqlcheck` and attachment reconciliation. The synthetic CI fixture now exercises the complete basic relationship chain.

These checks do not start the Laravel web application, prove complete financial accounting, check every domain table, or demonstrate recovery of real S3 data. Operational staging with a recovered MySQL snapshot and attachments remains mandatory before production.

## Owner API recovery smoke coverage (testing database)
`tests/Feature/OwnerPortfolioRecoverySmokeTest.php` exercises a full property-owner API path in Laravel's isolated in-memory SQLite testing database: create a property, unit, tenant and lease; verify two generated dues; record a payment and replay it with the same idempotency key; check due balances, owner dashboard, portfolio counts, payment listing and dated cashflow; and ensure another owner cannot access or modify the first owner's records. A separate test checks that guests cannot read property and financial resources.

CI includes the test in the Laravel security/integration job. This is **not** evidence that Laravel has booted against the actual recovered MySQL backup. That next gate requires a separately provisioned, isolated staging application (different secrets, database credentials, domain and document storage), a full restore rehearsal, and end-to-end owner browser tests. Never point an automated test that truncates databases at a production connection.

## Staging configuration preflight

Run `php artisan staging:preflight` after securely configuring and caching the **isolated staging** environment. This read-only gate refuses to pass unless `APP_ENV=staging`, `APP_DEBUG=false`, and the application URL uses non-local HTTPS. It requires the dedicated internal Compose MySQL host `db` on port 3306 with username `aqari_staging` and a MySQL database whose configured name contains `staging`, a persistent cache, an external SMTP mailer, a private local document disk, an S3-compatible **private** backup bucket, an independent 32-byte backup key, and enabled document malware scanning.

For example, the staging database might be named `aqari_staging`; do not reuse production database credentials or APP_KEY. On the isolated host, use `php artisan config:clear && php artisan staging:preflight`. This preflight does not start HTTP, connect to the database, check live backups, test actual SMTP delivery, or prove Docker is safe. It is only the first configuration gate before a full staging rehearsal. Record its output in deployment logs and stop deployment on any failure.

Do not run `php artisan test` or destructive migrations against a production database. CI test suites use an independent in-memory database. A staging release still needs a manually reviewed database connection, private storage, HTTPS certificate, ClamAV availability, backup verification and recovery rehearsal.

## Standalone staging Docker stack

A new `compose.staging-isolated.yaml` defines a separate Docker Compose project (`aqari-pro-staging`) with MySQL 8.4 on an internal-only network, an application endpoint bound to `127.0.0.1:18080`, separate named storage volumes and application outbound access for external SMTP/S3. It deliberately contains no public HTTPS gateway: add a separately hardened reverse proxy and DNS/certificate before browser testing. The existing `compose.yaml` and `compose.staging.yaml` are not used for this standalone stack.

See `STAGING_RUNBOOK.md` for a safe staging setup using secrets stored outside the source/build directory, reviewed boot commands, read-only `staging:preflight` and `migrate:status`, and browser/recovery acceptance gates. GitHub Actions includes a non-deploying `staging-compose-guard` job that renders Compose with **synthetic CI credentials** and checks ports and network isolation. This does not prove host availability or production readiness.

## One-time real-browser staging CRUD acceptance (opt-in)
The `browser-acceptance/tests/owner-write.spec.cjs` Playwright test now exercises the actual owner interface end-to-end with **synthetic test data only**: property → unit → tenant → lease with dues → 300 SAR receipt → cashflow report → private text attachment upload and byte-for-byte download → logout.

This write test is disabled by default and **never runs in GitHub Actions**. On a separately isolated staging server, an authenticated `GET /api/staging/e2e-readiness` must approve the test before any write. The gate requires `APP_ENV=staging`, HTTPS staging URL, dedicated `aqari_staging` database host/account, explicit `STAGING_E2E_WRITE_ENABLED=true`, an email matching `STAGING_E2E_OWNER_EMAIL` with `aqari-e2e-` prefix, and a **fresh database containing no other users and no existing property-management records**. A populated database returns HTTP 409; production and unapproved owners get HTTP 404. The browser also requires explicit `STAGING_E2E_WRITE_CONFIRMATION` from the operator.

The test intentionally leaves synthetic finance records intact, so it is one-time-per-disposable-database. Read `browser-acceptance/README.md` and `STAGING_RUNBOOK.md` for the opt-in procedure, secret handling and limitations. CI lints the script and tests the server gate but does not launch Chromium against a real staging URL; operational execution remains unverified.

## Browser acceptance mode separation (default deny)
The `browser-acceptance/` runner now selects **one** test family: `npm test` runs the read-only visitor/owner scenarios only, `npm run test:live` requires staging-owner credentials and fails before browser startup when missing, and `npm run test:write` is the separate one-time synthetic property/contract/collection/document scenario. The write suite is never selected by normal `npm test`, regardless of leftover confirmation variables. All three modes still enforce the exact approved staging HTTPS host; write requires an additional explicit confirmation and server-side `staging/e2e-readiness` gate.

## Exact-cent rent and lease renewal calculation

Both initial lease creation and renewal use `App\\Support\\RentInstallmentSchedule` to calculate positive two-decimal rent installments using **integer halalas (cents)** rather than IEEE-754 floating point conversion. The schedule distributes any remainder one cent at a time across the first installments and preserves the total. Contracts with more installments than whole cents are rejected, rather than creating zero-value receivables. The API also rejects rent amounts with more than two decimal places and constrains the accepted amount to at most 9,999,999,999.99 SAR.

The owner portal retains rent, deposit and payment amounts as decimal strings and sets 0.01 increments in money inputs. Tests cover creation, renewal, rounding remainders, and invalid near-zero amounts. The implementation is committed but a green CI and staging browser test are still needed before treating the financial workflow as verified.

## Owner portal navigation and financial precision follow-up

Recent hardening also prevents stale asynchronous section responses from replacing the owner's currently selected page. Navigation, alerts, deposit reporting, and reconciliation page loads have request-epoch checks, and the financial report ignores responses from views that have already been closed. The read-only Playwright owner acceptance scenario includes rapid navigation with a delayed API response.

The cashflow dashboard now normalizes sums to two decimal places and uses BCMath for the net amount rather than subtracting JavaScript/PHP floating point numbers. Expenses, maintenance actual costs, repair estimates, and damage estimates validate two-decimal precision with bounded maximums. The owner UI preserves decimal string inputs for rents, payments, deposits, damage estimates and maintenance final costs; record identifiers still use integer conversion.

The API regression tests cover a small four-installment lease split, partial receipts, refusing a one-halalah overpayment, refusing fractional-halalah expenses, and exact dashboard net values. These are source changes; live browser and production-shape MySQL acceptance testing remain outstanding.

## Contract duration and due-date scheduling safeguard

Lease creation and renewal now use `App\\Support\\LeaseDueDates` for due dates, in addition to the integer-cent amount allocator. A supported frequency (1, 2, 4 or 12 installments per year) must fit inside the contract's actual start/end dates. Invalid short terms fail validation on `installments`; the API no longer silently clamps excessive installments onto the contract's final date. Month-end due dates use Carbon's no-overflow calendar logic (for example January 31 → February 28). The first payment is due on the first lease day.

The companion `LeaseDueDatesTest` and `RentInstallmentApiTest` cover annual/month-end calendars and rejection of too-short contracts without inserting a lease. Actual calendar and database integration must pass CI before this is considered release-approved.

## Staging antivirus and S3 readiness hardening

The `staging:preflight --live` command now checks that ClamAV main/daily databases exist, are nonempty and that the daily signature file has a plausible, recent modification time. The default daily freshness limit is **168 hours**, configurable through `STAGING_SIGNATURE_MAX_AGE_HOURS` in `compose.staging-isolated.yaml`. An actual clean/EICAR harmless scanner test is performed separately by `staging:check-antivirus` and automatically required by `staging:check-services` **before** attempting any real S3 or SMTP operation.

`backup:check-storage` now handles S3 adapter initialization exceptions without treating them as successful checks. It requires its randomly named temporary object to be deleted and confirmed absent, even if the provider reports that the delete operation succeeded. Tests cover broken adapter startup, cleanup refusal, and objects that remain accessible after a reported deletion.

These safeguards are implemented and committed with PHPUnit coverage, but **GitHub Actions and an actual staging host must pass** before calling the restore, malware scanning or external storage readiness verified. See `STAGING_RUNBOOK.md` for first-run `freshclam` initialization and follow-up checks.

## Owner record lookup and dependent entry forms

The owner portal now offers **اختيار من السجلات** alongside the existing numeric ID input when creating units, leases, receipts, expenses and maintenance tickets. The searchable modal calls authenticated, rate-limited `GET /api/lookups/{resource}?q=` where `resource` is one of `properties`, `units`, `tenants`, `leases` or `vendors`. Search may use an ID or human-readable name, property city, unit code, tenant phone or vendor service. Responses contain at most 20 minimal `{id,label}` choices plus a `has_more` hint; users refine the search instead of downloading all owner records.

All lookup queries are scoped to the authenticated owner **before** text matching. The maintenance unit picker supplies `property_id`, so it shows units from the selected property only; changing that property clears any previously selected unit ID. The receipt picker uses `receivable=1` to exclude cancelled and completed leases, matching the payment API's constraints. The server still validates every submitted ID and its owning account independently of the picker.

`OwnerLookupTest` covers ownership boundaries, lookups by related names, phone/city/service search, pagination cap, invalid query inputs and dependent property/receipt filters. `tests/browser/owner-smoke.cjs` tests the picker on mobile and desktop while creating synthetic owner records and maintenance tickets. These checks run as part of the existing Laravel API GitHub Actions workflow.
