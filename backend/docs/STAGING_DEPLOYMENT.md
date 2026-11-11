# Aqari Pro — First-party HTTPS Staging Deployment

**Status:** deployment templates and CI smoke tests only. These instructions do **not** imply that a customer-facing host, domain, HTTPS certificate, database or tenant has already been provisioned.

## Architecture

The `backend/deploy/staging` directory builds a **complete Laravel 12 + Sanctum image** from the overlay in `backend/`, so the PHP service and front-end are deployable together. The private network contains:

- `app`: Laravel on Apache, with only `/public` available over HTTP inside Docker; all PHP source, environment settings and uploaded documents remain outside the document root.
- `db`: PostgreSQL 16 with a named persistent volume and no external published database port.
- `migrate`: one-shot Laravel migration service, completed before `app` becomes available.
- `proxy`: Caddy with public ports 80/443, automatic HTTPS issuance for the configured domain, security headers and a redirect from `/` to `/staging/`.
- `private_attachments`: named volume at Laravel's private storage path, shared by the migration and app containers. The volume needs **separate off-server encrypted backups**.

The Staging UI is available at `https://YOUR-STAGING-DOMAIN/staging/`. It sends cookie-authenticated requests to the **same domain** at `/login`, `/logout`, `/sanctum/csrf-cookie` and `/api/v1/*`. The public GitHub Pages demo remains separate and still stores synthetic browser-local data.

## Private host prerequisites

1. A dedicated Ubuntu/Debian server with a current supported Docker Engine and Docker Compose plugin. Configure OS updates, firewall, SSH controls, restricted operator accounts, storage monitoring and an offsite backup target.
2. A **staging-only domain** with a DNS A/AAAA record pointing at the server and reachable TCP ports 80/443. Remove unusable IPv6 AAAA records if the server cannot answer them. Do not reuse a production domain.
3. An operator-generated PostgreSQL password and a unique Laravel `APP_KEY`. Store both in a permissions-restricted file **on the server only**.
4. Allow outgoing requests for Caddy certificate issuance and for ClamAV signature updates.
5. Do **not** import genuine national identity, property ownership or tenant documents until the entire security and recovery gate is complete.

## Configure and start

On a private server, check out the repository under a non-public operator-owned directory and enter `backend/deploy/staging/`.

```bash
cp .env.staging.example .env.staging
chmod 600 .env.staging
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Set `AQARI_DOMAIN` to your DNS host, `AQARI_APP_KEY` to the generated output and `AQARI_DB_PASSWORD` to a long randomly generated secret. **Never share these values or commit the file.**

```bash
docker compose --env-file .env.staging -f compose.yaml config --quiet
docker compose --env-file .env.staging -f compose.yaml up --detach --build
docker compose --env-file .env.staging -f compose.yaml ps
```

Caddy obtains and stores TLS certificates in persistent volumes. The application Dockerfile installs ClamAV and the `clamscan` executable, but **definitions must be downloaded and kept current**. Configure a separate controlled signature-update schedule. To test an initial update:

```bash
docker compose --env-file .env.staging -f compose.yaml exec app freshclam --stdout
docker compose --env-file .env.staging -f compose.yaml exec app clamscan --version
```

If the scanner is missing, not updated, times out or rejects a file, the API fails closed and does not store the upload. Do not bypass scanning to make an upload succeed. A production installer should provision a dedicated signature-update service and alert on stale databases.

## Create first owner account

There is deliberately **no unauthenticated public registration** for this owner-only staging release. Use an interactive private terminal:

```bash
docker compose --env-file .env.staging -f compose.yaml exec app \
  php artisan aqari:provision-owner owner@example.com --name="Test Owner"
```

The command prompts for the password twice without displaying it or placing it in shell history. This creates the Laravel user and linked owner record. Use a **synthetic staging account**.

Open `https://YOUR-STAGING-DOMAIN/staging/` and sign in. Validate: properties → units → tenants → contracts/installments → receipts → receivables → private uploads/downloads. Only after confirming cookies, CSRF, real TLS, object scanning and owner isolation should external users be invited.

## Health and security checks

```bash
curl -fsS https://YOUR-STAGING-DOMAIN/up
curl -I https://YOUR-STAGING-DOMAIN/staging/
docker compose --env-file .env.staging -f compose.yaml exec -T app php artisan route:list --path=api
docker compose --env-file .env.staging -f compose.yaml exec -T app php artisan aqari:verify-attachments
```

**Expected:** `/up` returns HTTP 200, unauthenticated `/api/v1/properties` returns HTTP **401**, the browser receives a Secure session cookie under HTTPS, and browser authentication works with Sanctum's same-site CSRF cookie. The web login route is throttled. Verify that no Laravel application secrets are accessible under the public document root.

Do not publish the Postgres port; do not bind `app` to a public host port. Keep the Caddy container as the only public ingress.

## CI verification

The dedicated `Staging Container Integration` GitHub workflow creates a disposable configuration, validates Docker Compose and Caddy configuration, builds the image, runs a temporary database/migrations/app stack, checks `/up`, verifies the UI HTML and asserts `401` for an anonymous property request. It then destroys the temporary containers, volumes and sample secrets.

This is an **internal HTTP container test only**. It does not prove certificate issuance or end-to-end HTTPS on a real DNS name, or scanner signatures, customer data isolation under realistic load, external alert delivery or off-site restore capability.

## Production readiness gate

Before real data use: add controlled owner enrollment and recovery, MFA, secrets management, application/database backups with verified recovery, private file object backups including any S3-backed records, malware signature monitoring, storage quota and privacy retention controls, uptime/incident alerting, session/cookie security review, real browser E2E tests, and security penetration testing.

There is no real hosted staging service until these templates are deployed on an actual configured private server. Never confuse GitHub Pages deployment with Laravel Backend hosting.
