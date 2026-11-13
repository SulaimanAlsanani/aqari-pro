# Browser acceptance checks — Aqari Pro staging only

This is a deliberately **read-only** Playwright acceptance suite for the server-hosted owner portal at `/app/index.html`. It must never run against production or a restored database containing real owner records.

## Requirements

- Isolated staging HTTPS host, verified certificate and a separate set of synthetic test records.
- Node.js 20+, Chromium and Playwright. From `backend/browser-acceptance/`, run `npm install` and `npx playwright install chromium`.
- Set `STAGING_BASE_URL=https://staging.example.test` and `STAGING_ALLOWED_HOST=staging.example.test`. Replace both with the **same actual staging hostname**. The URL must contain a separate `stage` or `staging` segment; HTTP, localhost, IP addresses, URL credentials, unexpected paths and host mismatches are rejected before Playwright starts.
- For the owner journey, use a **dedicated non-production account without MFA**, populated from a secret store via `STAGING_TEST_OWNER_EMAIL` and `STAGING_TEST_OWNER_PASSWORD`. Never place these values in tracked files, CI logs or command-line arguments. The test does not automate MFA/recovery secrets. If those variables are absent, the authenticated test is skipped rather than declared passed.

## Commands

```bash
cd backend/browser-acceptance
npm install
npx playwright install chromium
npm run check
npm test
```

Provide environment variables through the operator's secure shell or secret injection; never add actual credentials to Git or a publicly visible `.env`. Running `npm run check` performs JavaScript syntax and staging URL-guard unit tests without connecting to the staging host.

## Coverage

- Guest sees the login form but not the workspace.
- Dedicated staging owner logs in through the real same-origin API, views dashboard metrics and navigates the properties, units, tenants, leases, dues, payments, documents and cashflow sections.
- Every section must receive HTTP 200 from its owner-scoped API endpoint, display meaningful UI content and avoid displaying an error state.
- Owner logs out through the API and returns to the login form.
- All cross-origin requests (including external fonts) are blocked. Tracing, screenshots and video are disabled to avoid recording financial, property or credential information.

**No CRUD writes or document uploads are performed by this suite.** Authentication may create/revoke a short-lived test token. A separate supervised operator acceptance rehearsal is required for create/edit/payment/upload workflows. A green browser suite requires both a real successful run and a dedicated test owner with seeded synthetic data. The CI pipeline runs only the offline target-guard checks and JavaScript syntax; it does not silently connect to staging from a pull request.
