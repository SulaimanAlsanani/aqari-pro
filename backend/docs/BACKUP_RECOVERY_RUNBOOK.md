# Aqari Pro — PostgreSQL backup and restore rehearsal

These scripts are **operational building blocks**, not an installed backup scheduler or a managed disaster-recovery service. Run them only on a private, access-controlled Linux host with PostgreSQL client tools and an approved staging/recovery database. Never run them in GitHub Pages.

## What the scripts provide

- `scripts/pg-backup.sh`: creates one PostgreSQL custom-format snapshot (`database.dump`), a SHA-256 checksum (`database.sha256`), and a plaintext non-secret `manifest.txt`.
- `scripts/pg-restore-verify.sh`: checks the checksum and archive contents, then restores **only into an already-created, empty database whose name begins with `aqari_restore_`**. Restores use a single transaction and never drop or overwrite an existing database.
- `php artisan aqari:verify-attachments`: checks that each attachment referenced by the restored database exists on its originally recorded private disk and has the recorded byte count. It reports IDs and errors, not original filenames or storage paths.

**The PostgreSQL dump does not contain any attachment file bytes.** S3 and private local objects must have an independent coordinated backup and restoration strategy.

## 1. Create a database backup

Install a PostgreSQL client compatible with your PostgreSQL server version. Supply connection credentials from a secrets manager or protected service environment, *not from Git source control or a public shell transcript*.

```bash
# PGHOST, PGPORT, PGDATABASE, PGUSER and PGPASSWORD:
# inject from a protected environment or secrets manager.
bash backend/scripts/pg-backup.sh /srv/aqari-private-backups/backup-2026-11-11-01
```

The destination must **not exist** beforehand, and the service account must have permission to create it. New directories use mode `0700` and archive/manifest/checksum use `0600`. The script refuses paths under the repository or a normal public web root; additional infrastructure restrictions are still your responsibility.

The script invokes `pg_dump -Fc` for a transaction-consistent database snapshot. It runs `pg_restore --list` and writes a SHA-256 checksum; these are integrity checks, **not backup encryption or proof of authenticity**. Never place dumps in a public directory, email them unencrypted, or commit them to Git.

## 2. Restore into a disposable database (never production)

An administrator must first provision a brand-new **empty** PostgreSQL database with a safe name, e.g. `aqari_restore_rehearsal01`. Use a disposable, isolated database server whenever possible.

```bash
# Provision through an approved administrative session:
createdb -h your-recovery-host -U authorized-operator aqari_restore_rehearsal01

# Configure PGHOST/PGPORT/PGUSER/PGPASSWORD for that recovery host.
bash backend/scripts/pg-restore-verify.sh \
  /srv/aqari-private-backups/backup-2026-11-11-01 \
  aqari_restore_rehearsal01
```

The restore script refuses an invalid database name, the source database name, a database with existing user tables, a missing file, an invalid checksum, an unreadable archive, or missing expected Aqari schema tables. It leaves the restored database in place for manual inspection. It **does not** call `dropdb`, change the primary database, restore real customer objects or expose a public endpoint.

Run smoke checks after the rehearsal: confirm users/owners/properties/contracts and payment reconciliation, verify owner isolation, validate attachment objects, and inspect PostgreSQL constraints/indexes. Use a temporary privacy-safe dataset for CI and staging demonstrations.

## 3. Restore and verify attachment objects separately

For local storage, ensure a separate encrypted snapshot of Laravel's private storage directory, with ownership and mode preserved. For S3-compatible storage, configure bucket versioning, lifecycle rules, provider-level backup or replication to an independently protected bucket, and appropriate server-side encryption. Coordinate the **database snapshot time** with file snapshots: concurrent uploads/deletions can create mismatches.

After restoring the database and a corresponding set of objects to a **private isolated recovery environment**, configure disk credentials to point at those recovery objects, preferably read-only. Then run:

```bash
php artisan aqari:verify-attachments
```

A nonzero exit code indicates a missing object, a byte-count mismatch, a disallowed disk, or a storage-access error. This check does **not** compare a cryptographic hash of each file, inspect its contents for malware or prove a file is legitimate. Do not run it with credentials that grant write access to production when inspecting an isolated rehearsal.

## 4. Retention, encryption and incident response

Before production, add infrastructure scheduling (cron/managed service), a documented encrypted **off-site** destination, key rotation and recovery, least-privilege service accounts, immutable/locked retention as legally appropriate, backup age/failure alerts, and scheduled restoration drills. Define target recovery point (RPO) and recovery time (RTO), and test them against realistic volumes. Only authorized staff should be able to request or perform a restore.

**GitHub CI coverage:** the disposable PostgreSQL 16 service is used to produce a new dump, restore into a newly created empty `aqari_restore_ci` database, verify required tables and migration data, and ensure a tampered archive and repeat restore into a populated destination are both rejected. These checks do not verify real-world backups, external object storage, encryption, cloud permissions or scheduled recovery.
