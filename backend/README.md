# Aqari Pro — Backend foundation (Laravel 12)

This directory contains **source files for a new Laravel 12 backend**, not a working deployed API. The GitHub Pages demo remains unchanged. Do not collect real customer data until this service is installed, configured, audited, backed up, and tested.

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

The source files `frontend/staging.html`, `frontend/staging-app.js`, and `frontend/api-session.js` implement a minimal **first-party staging user interface**. After signing in through a correctly configured Laravel session, the screens list and create properties, list and create units within an authorized property, and list and create tenants. They do not read or migrate data from the existing GitHub Pages localStorage demo.

To use them in an isolated non-public test environment, deploy the Laravel application behind HTTPS with stateful Sanctum configured, then place the three files under a restricted first-party route or staging-only public web root with the same-origin paths used by the API session client. Protect the staging hostname with access controls and never expose real customer data. The UI now supports Laravel paginator controls (previous/next) for each resource and safe DOM-based editing forms using PATCH for properties, units and tenants. Property options in the unit selector are limited to the currently displayed property page; no cross-page owner portfolio selector is implemented yet. Browser-level end-to-end testing is still outstanding. Authenticated requests use cookies and CSRF and never store secrets in localStorage.

The Pages workflow explicitly stages only root-level demo files, so these staging sources are not available at the public `github.io` URL. Automated Node tests verify the client's request paths and CSRF handling, but **live browser-to-server end-to-end testing still requires a staging server**.

## Secure payment posting — staging API

Authenticated endpoints:
- `GET /api/v1/payments` lists receipts for the current owner only.
- `GET /api/v1/payments/{payment}` returns receipt details with the owning contract, property, and tenant, scoped by owner.
- `POST /api/v1/dues/{due}/payments` posts a receipt with `amount`, `received_at`, `method`, and an optional `reference`. Ownership derives from the authenticated principal, never from client-provided identifiers.

For each posted payment the API locks the due row within a PostgreSQL transaction, totals existing receipts in integer halalas, rejects overpayments, and rejects duplicate nonblank payment references for the same due and method (case-insensitively). The response provides the receipt record. This is an internal receipt, not an Ejar or ZATCA tax document. Integration tests cover balances, duplicate references, cross-owner access and invalid amounts.

Payment creation currently has no refund, reversal, or idempotency-key workflow; those controls must be designed before real financial processing. The public GitHub Pages demo remains local-only and is not connected to these endpoints.

This is **foundation work**, not a promise that complete authentication, tenancy, documents, payments, or production infrastructure are implemented.
