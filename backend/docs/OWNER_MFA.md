# Aqari Pro — TOTP multifactor authentication for owners

**Status:** Staging implementation, not an independently audited production MFA service.

## Owner setup

1. Sign in with your usual email and password in the first-party Staging UI.
2. Under **المصادقة الثنائية (MFA)**, provide your **current password**.
3. The server generates 20 cryptographically random bytes encoded as a 32-character Base32 TOTP secret. The database stores **only Laravel-encrypted ciphertext**. The UI shows the secret and the `otpauth://` provisioning URI once; never include either in screenshots, support tickets, public QR generators or log files.
4. Add the key to an offline or locally managed authenticator supporting **RFC 6238 SHA-1, 6 digits and 30-second periods**.
5. Enter a fresh code to **confirm setup** within **10 minutes**. Enrollment does not enable MFA until the verification succeeds.
6. Enabling MFA revokes this owner's **other database sessions** and any existing Sanctum personal-access tokens. The current verified web session remains active.

## MFA-required login

- Correct password + enabled MFA returns **`mfa_required: true`** to the browser, without authenticating the user or exposing the portfolio.
- The browser supplies the 6-digit code to **`POST /login/mfa`**, protected by CSRF and a server-side throttle of five requests per minute.
- Pending challenges expire after **5 minutes** and also expire after **5 invalid attempts**. The pending identity exists only in the server-side session and does not grant authorization to Laravel API.
- The server accepts a single 30-second time step within a ±1-step clock tolerance and **atomically records the last accepted step**, so an already-consumed code cannot be reused by another request.
- Only a successful second factor creates the authenticated `web` session and logs the successful login.
- Failed codes may be recorded as `mfa_failed` in the owner-only login audit trail. They never log codes or secret keys.

## Disabling

Requires both current account password and an unused TOTP code, and it is available only to the authenticated owner. This clears the secret and records an audit event. Simply resetting the password **does not disable MFA**.

## Security limitations and operator prerequisites

- **There is no self-service MFA lost-device recovery or recovery codes yet.** Losing the authenticator may lock the owner out. Do not enable this for production customers until an audited identity-verified operator recovery process and/or one-time recovery codes have been completed.
- The TOTP secret is encrypted using Laravel `APP_KEY`. Keep a protected recovery copy of the correct application key separately from PostgreSQL and the encrypted offsite backups; losing it makes previously enrolled MFA secrets unrecoverable.
- Verify production HTTPS, CSRF, reverse-proxy trust and accurate host clock synchronization (NTP) before any real customer rollout.
- The implementation does not provide phishing-resistant WebAuthn/passkeys; TOTP codes are susceptible to phishing.
- Use database-backed sessions (`SESSION_DRIVER=database`) and restrict owner invite flows and access token issuance. Email password reset never automatically bypasses MFA.
- Browser tests use disposable synthetic accounts and independently generated TOTP codes; this is not a third-party penetration test.

## Routes

| Endpoint | Behavior |
|---|---|
| `POST /login` | Password validation; if MFA enabled, create unauthenticated pending challenge |
| `POST /login/mfa` | Consume one-time TOTP and establish verified session |
| `GET /account/mfa` | Return enabled state only (never secret) |
| `POST /account/mfa/start` | Validate current password, issue encrypted 10-minute setup secret |
| `POST /account/mfa/confirm` | Validate TOTP, enable MFA and revoke other sessions |
| `POST /account/mfa/disable` | Require current password + unused TOTP, then disable |

All routes require CSRF tokens on POST. Account setting routes require `auth:web`, and sensitive routes are throttled.
