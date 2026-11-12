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
The new `php artisan backup:make` command is deliberately constrained: it supports **SQLite only**, requires the application to already be in **maintenance mode**, and refuses archives larger than 128 MiB. It uses SQLite `VACUUM INTO` for a consistent database snapshot, packages private `owners/*/documents/*` attachments plus SHA-256 manifest in ZIP, validates all archived checksums and paths, then encrypts the archive with AES-256-GCM under a **dedicated BACKUP_ENCRYPTION_KEY** (base64 of 32 random bytes). The encrypted object is uploaded privately to the configured S3-compatible `backup` disk, downloaded, compared by digest, and checked for authenticated decryption. Working files are deleted after the command.

**Prerequisites:** PHP extensions openssl and zip, S3 adapter installed by Composer, a correctly configured private backup bucket, separate encryption-key escrow, available disk space and configured `BACKUP_ENCRYPTION_KEY`. On staging only, enter maintenance mode with `php artisan down`, run `php artisan backup:check-storage` then `php artisan backup:make`, and restore service with `php artisan up` after verifying results. This command does not automatically manage maintenance mode.

**Limitations / launch blockers:** This is not production-grade disaster recovery. MySQL is unsupported; the snapshot operates in memory up to 128 MiB; attachment changes by external writers during maintenance could affect consistency; a full restore command, independent isolated restore drill, managed scheduled backups, retention enforcement, encryption-key rotation and operational monitoring remain outstanding. Never restore an unverified archive over a live database.

## Read-only backup verification and isolated restore staging

For an existing encrypted backup object with a full path returned by `backup:make`:
- `php artisan backup:verify snapshots/YYYY/MM/DD/HHMMSS-UUID.aqbk` authenticates and decrypts the remote object into an ephemeral private directory, verifies all ZIP entries against the SHA-256 manifest, and removes its temporary copy. No live database or attachments are touched.
- `php artisan backup:stage-restore snapshots/YYYY/MM/DD/HHMMSS-UUID.aqbk` performs the same envelope and archive validation, then writes individual allowlisted files to a **new random directory under `storage/app/restore-staging/`** and runs `PRAGMA integrity_check` against the recovered SQLite database. It never replaces live data and does not update application configuration. Restrict access to this directory (sensitive data is decrypted during the drill). Delete it securely after validation.

Both commands require the independent backup key, a private S3 disk, and PHP ZIP support. They enforce the 128 MiB encrypted backup-size limit and reject unexpected archive members, missing checksums, oversized expansion and malformed object keys. Run staged restore only on an isolated non-production host with separate credentials.

**Not yet an automatic in-place recovery path:** service recovery still requires an approved maintenance procedure, a separate database and attachment reconciliation, verification of application startup, a restore rehearsal, and retention/monitoring controls. Do not use the staged data in production directly. MySQL support remains unimplemented.
