# Aqari Pro — Backend foundation (Laravel 12)

This directory contains **source files for a new Laravel 12 backend**, not a working deployed API. The GitHub Pages demo remains unchanged. Do not collect real customer data until this service is installed, configured, audited, backed up, and tested.

## Encrypted attachment backups and freshness monitoring

`scripts/offsite-attachments.sh` is the primary owner-data attachment backup for both local and S3 records referenced by PostgreSQL. In addition, `scripts/offsite-local-attachments.sh` can encrypt a supplemental snapshot of only the local private storage directory, including a per-file SHA-256 manifest and strict symlink rejection. The latter does **not** cover S3-backed files.

`scripts/check-backup-freshness.sh` fails if the latest **completed** encrypted PostgreSQL backup or primary all-attachments backup is missing, stale, or inaccessible (default: 30 hours). Templates in `deploy/systemd/aqari-backup-monitor.service` and `.timer` allow a host operator to run this check every six hours; they are **not enabled or connected to any notification provider**. Backup stream prefixes and access permissions must be configured on the private server.

See [backup monitoring and attachment snapshots](docs/BACKUP_MONITORING.md). CI tests simulate local attachment recovery, missing/stale backups and storage outages using mock S3. This is **not** evidence that production backups, buckets, credentials, monitoring alerts or customer-file recovery have been configured.

## Opt-in encrypted off-site PostgreSQL backup scheduler

The `scripts/offsite-backup.sh` tool now creates a temporary private PostgreSQL snapshot, bundles its dump/checksum/manifest, encrypts it **before upload** using a public `age` recipient, removes the plaintext snapshot, and uploads only an encrypted `.tar.age` object into a private S3-compatible bucket. The script requests SSE-AES256, verifies the remote object size, and cleans up on success or failure.

`deploy/systemd/aqari-offsite-backup.service` and `.timer` supply an **example daily 02:15 server-time schedule**, hardened service settings and an external secrets file. They are **not installed or enabled on any host**. No cloud credentials or private encryption identities are committed.

`scripts/ci-encrypted-backup.sh` tests the entire envelope with a temporary `age` identity, mock private object storage, decrypt/restore archive inspection, invalid recipients and simulated upload failure. It does **not** verify a real AWS/S3 account or production backup retention.

See [encrypted off-site backup deployment and recovery](docs/ENCRYPTED_OFFSITE_BACKUPS.md). **Database archives still exclude private attachment file bytes**, which need independent private backups and a coordinated restore drill. Real KMS/IAM, scheduling, alerts, recovery key custody, off-site attachment copies and HTTPS staging are pending.

## PostgreSQL backup, isolated restoration and private-object audit

New **staging/recovery** scripts and CLI checks:

- `scripts/pg-backup.sh` produces a custom-format PostgreSQL archive with SHA-256 checksum in a new mode-0700 private directory; it refuses overwriting existing backups or writing under the repository/public web root.
- `scripts/pg-restore-verify.sh` verifies the archive checksum, then restores only into an explicitly provisioned, **empty** `aqari_restore_*` database within one transaction. It does not drop or overwrite databases.
- `php artisan aqari:verify-attachments` checks private `local`/`s3` attachment objects for existence and recorded byte size; missing/truncated/unsupported objects cause exit code 1. This is **not** a cryptographic per-object verification.
- GitHub's PostgreSQL CI now tests restore to an ephemeral database, confirms restored migration data, and verifies that tampered archives and repeat restores into nonempty databases are rejected.

See [Backup and recovery runbook](docs/BACKUP_RECOVERY_RUNBOOK.md) for prerequisites and commands. **A PostgreSQL dump excludes all private attachment bytes.** Private storage backups, encryption, external retention, scheduled backup jobs, live restore drills and RPO/RTO monitoring have not been deployed.

## Reproducible first-party HTTPS staging stack

The `deploy/staging/` directory now contains a **buildable Laravel 12 + Sanctum Docker image**, Docker Compose definitions for a private PostgreSQL 16 container, one-shot migrations, persistent attachment volume and Caddy automatic HTTPS, plus a first-party copy of the complete staging frontend. Only the HTTPS proxy publishes host ports. The app, PostgreSQL and private document volumes remain on the internal Docker network.

To deploy on your own private host, see [Staging deployment and security checklist](docs/STAGING_DEPLOYMENT.md). Create a server-only `.env.staging` based on the included example, provision a real staging DNS record and then use Docker Compose. An initial account can be created with the **interactive** `php artisan aqari:provision-owner` command, which does not accept passwords as CLI arguments.

The GitHub [Staging Container Integration](../../.github/workflows/staging-smoke.yml) job builds the image and runs PostgreSQL migrations, health checks, anonymous API access isolation and a staging HTML smoke test using temporary containers. It also checks Caddy syntax; it does **not** obtain a real public TLS certificate or deploy a reachable customer service. The GitHub Pages demo is not this backend.

## Install into a fresh Laravel 12 application

1. Provision a **private** HTTPS application server and managed PostgreSQL database.
2. Create the Laravel application using the official Laravel installer/Composer, initialize Git, and install Laravel Sanctum via the official Laravel API installation flow.
3. Copy the files from this directory to the corresponding paths in the Laravel application. Review and resolve conflicts with Laravel's standard users migration and User model before running migrations.
4. Keep `APP_DEBUG=false`, `APP_ENV=production`, and all `.env` files and secrets outside the public GitHub Pages assets. Configure `DB_CONNECTION=pgsql` with non-public credentials.
5. Run `php artisan migrate` and `php artisan test` on an isolated staging database. Run threat modeling, access-control testing, backup/restore drills and review CORS/session/CSRF settings before real use.

## Access model

- v1: one **owner account** (Laravel user) controls exactly one `owners` record through `owners.user_id` (unique).
- All property queries must derive owner identity exclusively from `$request->user()->id`. Never accept `owner_id` from a browser form.
- Nested resources require verifying the parent entity belongs to the authenticated owner (cross-owner access returns 404).
- Record amounts in `decimal(15,2)` columns; never use binary floats for financial posting.
- Do not write public files or personal documents to GitHub Pages.
- Laravel Sanctum's SPA-cookie mode requires correct stateful domains and CSRF configuration. API token mode may be used for a separate trusted client only after security review.

## Included files

- `database/migrations/2026_11_09_000001_create_owner_portfolio_tables.php`: baseline relational schema.
- `app/Models/Owner.php`, `app/Models/Property.php`, `app/Http/Controllers/Api/PropertyController.php`: first owner-scoped property endpoint.
- `app/Models/Unit.php`, `app/Models/Tenant.php`, `app/Http/Controllers/Api/UnitController.php`, `app/Http/Controllers/Api/TenantController.php`: authenticated nested units and owner-scoped tenants.
- `tests/Feature/PortfolioIsolationTest.php`: cross-owner access, parent validation, and ownership-injection regression tests.
- `app/Models/Contract.php`, `app/Models/Due.php`, `app/Services/InstallmentSchedule.php`, `app/Http/Controllers/Api/ContractController.php`: owner-scoped contracts and atomic monthly installment creation; rejects overlapping date windows and uses integer halalas for precise allocation.
- `tests/Feature/ContractCreationTest.php`: tests cross-owner access, overlap conflicts, rollback safety, monthly date anchors and exact cents.
- Contract creation uses a PostgreSQL row lock on the unit to serialize concurrent writers; after authentication, the client can call `POST /api/v1/contracts` with `unit_id`, `tenant_id`, `start_date`, `end_date`, `total`, and `installments`. This is still a staging-only foundation, not an Ejar integration.
- Units are created only beneath a verified owner property; tenant `owner_id` is generated from the authenticated user. No delete endpoints are exposed yet.
- `routes/api.php`: authenticated API resource registration.
- `tests/Feature/PropertyIsolationTest.php`: isolated-user authorization regression tests.

## Session authentication and staging frontend connector

- `POST /login` uses Laravel's web session guard, returns a minimal user profile, rotates the session ID after successful login, and is throttled to five attempts per minute.
- `POST /logout` invalidates the authenticated web session and regenerates its CSRF token.
- `GET /api/v1/me` requires `auth:sanctum` and returns only basic user and owner identifiers, never password hashes or session tokens.
- `backend/frontend/api-session.js` is an **opt-in staging-only** client with credentialed requests and a configured CSRF-token reader. It is not shipped in the public GitHub Pages bundle or wired into the existing local-storage demo.

For a browser-based first-party SPA, host the UI and Laravel API on the **same site** under HTTPS, configure `SANCTUM_STATEFUL_DOMAINS`, session cookie domain, secure/SameSite cookie settings and exact trusted CORS origins. Obtain `/sanctum/csrf-cookie` before login. Pass a `csrfToken` function that reads only the URL-encoded `XSRF-TOKEN` cookie for the `X-XSRF-TOKEN` header. Do not store user passwords, Laravel session cookies, or API tokens in localStorage. The standalone github.io page is a different site from a custom Saudi domain, so do not treat cross-site cookie login there as production-ready.

User provisioning/registration, password reset, email verification, MFA, production session storage, secure-domain deployment and full user/permission management are **not implemented yet**. The session controller is a foundation for deployment on a properly configured first-party site, not a claim of production-ready identity management.

## Separate staging interface for properties, units and tenants

The source files `frontend/staging.html`, `frontend/staging-app.js`, `frontend/finance-staging.js`, and `frontend/api-session.js` implement a minimal **first-party staging user interface**. After signing in through a correctly configured Laravel session, the screens list and create properties, list and create units within an authorized property, and list and create tenants. They do not read or migrate data from the existing GitHub Pages localStorage demo.

To use them in an isolated non-public test environment, deploy the Laravel application behind HTTPS with stateful Sanctum configured, then place the four files under a restricted first-party route or staging-only public web root with the same-origin paths used by the API session client. Protect the staging hostname with access controls and never expose real customer data. The UI now supports Laravel paginator controls (previous/next) for each resource and safe DOM-based editing forms using PATCH for properties, units and tenants. Property options in the unit selector are limited to the currently displayed property page; no cross-page owner portfolio selector is implemented yet. Browser-level end-to-end testing is still outstanding. Authenticated requests use cookies and CSRF and never store secrets in localStorage.

The Pages workflow explicitly stages only root-level demo files, so these staging sources are not available at the public `github.io` URL. Automated Node tests verify the client's request paths and CSRF handling, but **live browser-to-server end-to-end testing still requires a staging server**.

## Contracts, installments, receipts and receivables — staging UI

The separate `frontend/finance-staging.js` module extends `staging.html` (loaded after `api-session.js`) and integrates into `staging-app.js`. It provides a working staging client flow for:

- Selecting an owner-scoped property, one of its units, and an owner-scoped tenant; creating a **draft contract** through `POST /api/v1/contracts` with monthly installment scheduling.
- Paginating the owner's contracts and displaying `GET /api/v1/contracts/{id}`, including due dates, outstanding balances, and posted/reversed receipt history.
- Posting an installment receipt through `POST /api/v1/dues/{id}/payments`, and deliberately reversing an existing receipt with an obligatory reason through `POST /api/v1/payments/{id}/reverse`. Reversal does not delete original receipt history.
- Paginating receipt history and rendering `GET /api/v1/reports/receivables` with property filtering and overdue aging bands.

Monetary display in the staging module uses **integer halalas**, and reversed receipts do not reduce outstanding balance. All client-rendered labels are assigned as text, not untrusted HTML. Backend ownership, payment limits and financial invariants remain authoritative.

The staging selectors fetch a bounded maximum of 25 pages (at most 500 records per lookup); larger portfolios require searchable server-backed selectors. The UI is **not connected** to the localStorage GitHub Pages demo, and is **not live on a staging host**. Live browser E2E tests, permanent PostgreSQL, server-side HTTPS/cookie configuration, backups, user provisioning, data migration, and complete security review remain prerequisites for release.

## Malware-scanned, configurable private document storage

Private uploads now run through `app/Services/AttachmentScanner.php` before permanent persistence. ClamAV is **mandatory**: infected files are rejected with validation errors and scanner errors/missing executables return HTTP 503 without saving files. Configure `AQARI_CLAMAV_BINARY` and `AQARI_CLAMAV_TIMEOUT_SECONDS` on the private app server.

`AQARI_ATTACHMENT_DISK=local` (default) uses Laravel's private local disk. `AQARI_ATTACHMENT_DISK=s3` opts into a configured private S3-compatible disk; each file records its storage disk so previously uploaded objects remain downloadable when the default changes. The code stores attachments with private visibility, but the external bucket, IAM permissions and server-side encryption must be configured and verified outside the repository.

The `attachment_events` table records who uploaded a file and each authorized `download_requested` operation. Events are visible only to the owner through `GET /api/v1/attachments/{attachment}/events`. The download audit action does not guarantee successful completion of browser transfer.

See [private document deployment checklist](docs/PRIVATE_DOCUMENTS_DEPLOYMENT.md). S3 integration uses fake disks in CI; real ClamAV installation, object storage encryption, external backups and a restored HTTPS staging deployment are still unverified prerequisites.

## Private property and contract attachments (staging)

New files: `database/migrations/2026_11_11_000003_create_private_attachments.php`, `app/Models/Attachment.php`, `app/Http/Controllers/Api/AttachmentController.php`, `tests/Feature/PrivateAttachmentsTest.php`, and `frontend/attachment-staging.js` with the upload panel in `staging.html`.

Authenticated API endpoints: `GET|POST /api/v1/properties/{property}/attachments`, `GET|POST /api/v1/contracts/{contract}/attachments`, `GET /api/v1/attachments/{attachment}/download`.

The server verifies owner identity before looking up the target property/contract. The PostgreSQL schema enforces exactly one parent. Files use Laravel's private local disk under `owner-documents/{owner-id}/` with generated names. API responses hide internal storage paths. Downloads require authentication, force attachment disposition, disable caching, and set `nosniff` and sandbox headers. The staging upload form uses multipart requests and CSRF without storing secrets in localStorage.

**Current safeguards:** PDF, JPEG and PNG only; maximum 10 MiB; server-side MIME checks; owner-scoped metadata and downloads. Laravel/PostgreSQL tests cover valid uploads, invalid types, oversized files, missing objects, unauthenticated downloads and cross-owner access.

**Required before production:** deploy and verify real ClamAV/signature updates, provision encrypted external object storage, verified backup/restore, upload quotas, retention/deletion rules, durable audit-log retention, HTTPS and CORS review, and real browser E2E tests. File scanning gates, selectable private disks and basic attachment events are coded but NOT independently verified on production infrastructure. Do not upload real identity records, deeds or tenant documents to the public demo or an unreviewed deployment.
## Secure payment posting — staging API

Authenticated endpoints:
- `GET /api/v1/payments` lists receipts for the current owner only.
- `GET /api/v1/payments/{payment}` returns receipt details with the owning contract, property, and tenant, scoped by owner.
- `POST /api/v1/dues/{due}/payments` posts a receipt with `amount`, `received_at`, `method`, and an optional `reference`. Ownership derives from the authenticated principal, never from client-provided identifiers.

For each posted payment the API locks the due row within a PostgreSQL transaction, totals existing receipts in integer halalas, rejects overpayments, and rejects duplicate nonblank payment references for the same due and method (case-insensitively). The response provides the receipt record. This is an internal receipt, not an Ejar or ZATCA tax document. Integration tests cover balances, duplicate references, cross-owner access and invalid amounts.

Payment reversals now preserve the original record and append an audit event, but automated refund disbursement, a dedicated idempotency-key workflow, role approvals and reconciliation controls are still outstanding. The public GitHub Pages demo remains local-only and is not connected to these endpoints.

This is **foundation/staging implementation work**, not a claim of a production-ready identity system, sensitive-document storage, financial ledger, or deployed production infrastructure.
