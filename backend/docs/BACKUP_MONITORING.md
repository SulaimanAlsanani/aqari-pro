# Aqari Pro — Backup freshness monitoring and attachment snapshots

## What now exists

The production-facing backup scripts generate completion markers (`.tar.age.ok`) **only after** the encrypted remote object has been uploaded and its size checked. The new `backend/scripts/check-backup-freshness.sh` inspects those markers on the private S3-compatible backup bucket.

Two independent streams are required:

| Stream | Default object prefix | Writer |
|---|---|---|
| PostgreSQL | `aqari-pro/postgresql/` | `offsite-backup.sh` |
| All database-referenced private attachments, local and S3 | `aqari-pro/attachments/` | `offsite-attachments.sh` |

By default, the monitor fails if either stream has **no successful completion marker within 30 hours** or its storage is inaccessible. Override this with `AQARI_BACKUP_MAX_AGE_HOURS` between 1 and 720.

Configure a private server service environment with `AQARI_BACKUP_BUCKET`, the two prefixes (`AQARI_BACKUP_PREFIX`, `AQARI_ATTACHMENTS_BACKUP_PREFIX`), AWS CLI authentication, and the permitted age limit. The account needs **ListBucket** permission for the required prefixes (the backup upload account should not necessarily have this permission; use a separate read-only monitor identity).

```bash
bash backend/scripts/check-backup-freshness.sh
```

The command returns exit code 1 when either stream is missing, too old or inaccessible, and 0 when both are fresh.

## Service and alert integration

The repository includes **inactive** deployment templates:

- `backend/deploy/systemd/aqari-backup-monitor.service`
- `backend/deploy/systemd/aqari-backup-monitor.timer`

The example timer checks every six hours. Installing the service does **not** automatically deliver email, SMS, PagerDuty or other alerts. Configure a production monitoring agent or systemd `OnFailure` integration that notifies the responsible operators on nonzero exit, then intentionally simulate backup outages to confirm notification delivery.

A green freshness check only establishes that completed objects were recently reported. It does **not** prove an object can be downloaded, decrypted, restored or matched to a database snapshot. Keep routine independent recovery drills and S3 object replication/storage health checks.

## Local-only attachment snapshots

`backend/scripts/offsite-local-attachments.sh` is an **optional complementary backup** that encrypts a snapshot of the private local attachment directory configured through `AQARI_PRIVATE_ATTACHMENTS_DIR`. It rejects symlinks/special files, includes per-file SHA-256 checksums, and writes encrypted ciphertext and a verified completion marker under `AQARI_ATTACHMENT_BACKUP_PREFIX` (default `aqari-pro/local-attachments/`).

This does **not** back up S3-backed attachments and is therefore **not a replacement** for the database-referenced all-attachments export (`offsite-attachments.sh`). The combined backup freshness monitor intentionally tracks the latter, not the local-only supplemental stream.

In CI, a temporary sample private directory is encrypted and uploaded to a fake private bucket, decrypted into an isolated folder, checked against original file bytes and checksums, and tested against upload failure and symbolic-link rejection.

## Remaining release gates

Do not deploy this system with customer identity documents until all of the following are verified on an access-controlled staging server:

1. Read-only monitoring identity plus private S3 bucket and encrypted/locked retention policy.
2. Working daily database and all-attachments backup services with non-overlapping snapshots or an explicitly documented consistency window.
3. Independent alarm delivery for failed jobs and stale markers, confirmed by a deliberately induced failure.
4. Full download/decrypt/restore drill that also verifies owner isolation and document byte integrity.
5. A tested key-custody and key-recovery procedure. Never commit or paste private keys in application source.

The GitHub Actions tests use mock storage and synthetic documents. They do not provision any real cloud resources or activate the systemd timers.
