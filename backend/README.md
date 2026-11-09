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

This is **foundation work**, not a promise that complete authentication, tenancy, documents, payments, or production infrastructure are implemented.
