# Aqari Pro — Local Docker startup (Windows / macOS / Linux)

This is the **real Laravel 12 + PostgreSQL 16 staging application**, not the independent GitHub Pages browser demo. It runs on **your own computer**, without Hostinger, DNS, external TLS certificates or cloud accounts.

## 1. Prerequisites

- Docker Desktop with **Linux containers** (Windows: WSL2 backend), or Docker Engine plus Compose v2 on Linux.
- Git; Internet access for the first Docker image and Composer dependency build.
- Approximately 4–8 GB free RAM and sufficient disk space for PostgreSQL and Docker images.
- Windows: PowerShell 5.1+; macOS/Linux: Bash and OpenSSL.

First, in a terminal:

```bash
git clone https://github.com/SulaimanAlsanani/aqari-pro.git
cd aqari-pro
```

If the GitHub repository is private, authenticate with an account that has access.

## 2. Start all services

**Windows PowerShell** (from the repository root, with Docker Desktop already running):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\backend\deploy\local\start.ps1
```

The ExecutionPolicy override applies to this PowerShell process only. Inspect the local script before running it if you prefer.

**macOS / Linux:**

```bash
bash backend/deploy/local/start.sh
```

The starter scripts create `backend/deploy/local/.env.local` **once**, with cryptographically generated credentials, and then validate/build/start:

- `db`: PostgreSQL 16 on an internal Docker network with a persistent volume.
- `migrate`: Laravel migrations that run once the database is ready.
- `app`: Laravel, Sanctum, first-party Arabic interface, and private attachment storage.

The only host-exposed port is **127.0.0.1:8080** to Apache. The PostgreSQL port is **not** published on your computer.

Open **http://localhost:8080/staging/**.

You can change `AQARI_LOCAL_PORT=8080` in the private `.env.local` before starting if port 8080 is already in use. Use a value from 1024 to 65535. **Do not delete your generated `.env.local` while retaining the PostgreSQL data volume**, or the database password may no longer match.

## 3. Create the first owner

From the repository root:

```bash
docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml exec app php artisan aqari:provision-owner owner@example.com --name="Test Owner"
```

Choose your own email and name. The interactive command asks for a password twice without displaying it. Sign in at the local UI above. There is intentionally no public registration.

## 4. Uploading private documents

The default real **ClamAV** executable is included in the image and fails closed if definitions are absent or outdated. On your machine, download a real signature database before trying document uploads:

```bash
docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml --profile maintenance run --rm clamav-update
```

This needs outbound network access to ClamAV signature servers and may be rate-limited. It stores signatures in a shared Docker volume, **not** in the Git repository. Do not substitute the separate CI-only fake scanner. If the updater cannot retrieve signatures, file uploads remain blocked.

Password reset emails also intentionally remain **disabled in local mode** (`MAIL_MAILER=log` is blocked by the recovery controller) to avoid disclosing reset tokens in logs. Use the authenticated password-change UI or the private operator workflow.

## 5. Monitoring, shutdown and data

Health check:

```bash
curl http://localhost:8080/up
```

Container status and logs:

```bash
docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml ps
docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml logs --tail=100 app db
```

Stop the containers **without deleting data**:

```bash
docker compose --env-file backend/deploy/local/.env.local -f backend/deploy/local/compose.yaml down
```

Start again using the same startup script. PostgreSQL and private attachments remain on persistent named volumes. **Never run `down --volumes` or `down -v` unless you deliberately intend to erase local records**.

## Security and scope

- This is HTTP strictly bound to the **loopback address**; do not expose or forward port 8080 onto a public network.
- It is for local development and synthetic testing, **not** production or real identity documents.
- SMTP, automatic off-site backups, live TLS, MFA and active malware-signature monitoring are not configured locally.
- Docker Desktop/Engine is required to run the *full* backend stack. Opening `index.html` at the repository root runs only the disconnected GitHub Pages demo.
- The environment and database are on your machine; GitHub Actions tests do not start services on your computer.
