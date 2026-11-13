# Browser acceptance checks — Aqari Pro staging only

The suite includes **read-only checks by default** and an independent, explicitly opt-in **one-time synthetic write journey** for the server-hosted owner portal at `/app/index.html`. It must never run against production, shared users, or restored databases containing real owner records.

## Requirements

- Isolated staging HTTPS host, verified certificate and a separate set of synthetic test records.
- Node.js 20+, Chromium and Playwright. From `backend/browser-acceptance/`, run `npm install` and `npx playwright install chromium`.
- Set `STAGING_BASE_URL=https://staging.example.test` and `STAGING_ALLOWED_HOST=staging.example.test`. Replace both with the **same actual staging hostname**. The URL must contain a separate `stage` or `staging` segment; HTTP, localhost, IP addresses, URL credentials, unexpected paths and host mismatches are rejected before Playwright starts.
- For the owner journey, use a **dedicated non-production account without MFA**, populated from a secret store via `STAGING_TEST_OWNER_EMAIL` and `STAGING_TEST_OWNER_PASSWORD`. Never place these values in tracked files, CI logs or command-line arguments. The test does not automate MFA/recovery secrets. If those variables are absent, the authenticated test is skipped in the basic `npm test` suite. Use `npm run test:live` when verifying an actual release: this command **fails before browser startup** if credentials are missing.

## Commands

```bash
cd backend/browser-acceptance
npm install
npx playwright install chromium
npm run check
npm test           # Guest and read-only tests; owner journey is skipped without credentials
npm run test:live  # Requires dedicated staging owner credentials; will fail if absent
```

Provide environment variables through the operator's secure shell or secret injection; never add actual credentials to Git or a publicly visible `.env`. Running `npm run check` performs JavaScript syntax and staging URL-guard unit tests without connecting to the staging host.

## Coverage

- Guest sees the login form but not the workspace.
- Dedicated staging owner logs in through the real same-origin API, views dashboard metrics and navigates the properties, units, tenants, leases, dues, payments, documents and cashflow sections.
- Every section must receive HTTP 200 from its owner-scoped API endpoint, display meaningful UI content and avoid displaying an error state.
- Owner logs out through the API and returns to the login form.
- All cross-origin requests (including external fonts) are blocked. Tracing, screenshots and video are disabled to avoid recording financial, property or credential information.

**Only the explicitly opted-in `owner-write.spec.cjs` test performs CRUD writes and a document upload.** Without the dedicated server flag and explicit browser confirmation, it is skipped. Authentication may create/revoke a short-lived test token. A green browser suite requires both a real successful run and a dedicated test owner with seeded synthetic data. The CI pipeline runs only the offline target-guard checks and JavaScript syntax; it does not silently connect to staging from a pull request.

## One-time end-to-end write acceptance — staging only

This test **changes data**, creates a lease and a financial receipt, and saves a synthetic text attachment. Run it exclusively on the separate disposable staging database and with a freshly provisioned dedicated user. Do not enable it on a shared or recovered production dataset.

1. On the staging deployment only, configure `STAGING_E2E_WRITE_ENABLED=true` and `STAGING_E2E_OWNER_EMAIL=aqari-e2e-<unique-id>@your-test-domain` in the private staging secrets file. The email must begin with `aqari-e2e-`. The dedicated test owner must be the **only user** in a newly provisioned disposable staging database. There must be **no existing property, unit, tenant, vendor, document, lease, payment, due, expense or maintenance records anywhere in that database**. **The staging Compose stack disables this flag by default.** Restart/recreate the staging app to apply a changed environment.
2. The test runner must have `STAGING_TEST_OWNER_EMAIL` matching the configured server-side E2E owner email, and `STAGING_TEST_OWNER_PASSWORD` from a secret provider. The test owner must not have MFA enabled; do not automate recovery codes.
3. Only after verifying that this is a disposable test host and synthetic account, set `STAGING_E2E_WRITE_CONFIRMATION=ALLOW_ONE_TIME_SYNTHETIC_WRITES` in the test-runner process environment. Run **`npm run test:write`** from `backend/browser-acceptance/`. The normal `npm test` command never selects the write scenario, even when write-related environment variables remain set. Do not put these values in commit history or shell commands recorded by CI.
4. The test logs in and calls authenticated `GET /api/staging/e2e-readiness` before **any financial or document write**. The server returns 404 unless it is configured as isolated staging with HTTPS, the `aqari_staging` MySQL service/account, an explicit flag, and the exact dedicated user. It returns 409 if there is any other user or existing property-management data in the database, including unrelated owners' records. The browser aborts on either response; it cannot bypass the server guard.
5. On success, the test uses the real browser forms to create one synthetic property, unit, tenant, two-installment lease, a 300 SAR receipt, and a harmless text attachment; it then checks installment balances, verifies the dated cashflow report is 300 SAR, and downloads the exact attachment bytes through the UI. No real customer data, screenshot, trace or video is used.
6. **This is intentionally one-time per dedicated test account.** Records are not automatically deleted, because deleting a lease, receipt or document as cleanup could cause unintended business-data changes. Review the synthetic rows and separately discard the disposable staging database or provision a new dedicated test account for another run. Immediately disable `STAGING_E2E_WRITE_ENABLED` after acceptance.

The read-only tests can continue to run independently. The offline CI workflow syntax-checks this write suite and tests server gate denial; it **does not perform or enable the write journey automatically**. A green CI run is not proof that real Staging E2E passed.

## Explicit execution modes

The runner at `scripts/run-acceptance.cjs` selects exactly one scenario family per invocation: `npm test` (read-only), `npm run test:live` (read-only with required account credentials), or `npm run test:write` (single synthetic write journey with one-time confirmation). It uses only the locally installed Playwright CLI. GitHub Actions validates the runner syntax and mode selection tests, but does **not** start a live browser or authorize writes. Do not launch a write scenario by invoking the raw Playwright CLI; use the guarded npm commands so the tests cannot be selected accidentally.
