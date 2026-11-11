# Aqari Pro — Encrypted off-site PostgreSQL backups

This is an **opt-in service configuration for a future private staging/production server**. It is not deployed on the public GitHub Pages demo, and the repository contains **no customer backups, cloud credentials, or private decryption keys**.

## What is implemented

- `backend/scripts/offsite-backup.sh` runs the tested PostgreSQL backup script in a temporary private workspace.
- It wraps `database.dump`, `database.sha256` and `manifest.txt` in a TAR stream, encrypts that stream with **age** to a configured public recipient, and deletes the plaintext dump **before** uploading.
- It sends only one encrypted `.tar.age` object through `aws s3api put-object`, requesting server-side AES256 encryption as an additional layer, and checks the remote object size using `head-object`.
- It cleans up its temporary work directory on success or failure. Incorrect recipients and cloud upload failures prevent success.
- `backend/deploy/systemd/aqari-offsite-backup.service` and `.timer` are **installation examples**, not an active server timer.
- CI generates an ephemeral age identity, uploads into a private mock object store, decrypts the object, validates the PostgreSQL dump and checksum, and injects failed encryption/upload cases.

**Scope limitation:** the encrypted bundle contains only the PostgreSQL database. Attachments stored under Laravel private local storage or S3 are **not** included. Arrange separately versioned, encrypted, private object backups and test that their recovery point is consistent with the database.

## Preparation on a private Linux application server

Install PostgreSQL client tools compatible with the database server, [age](https://github.com/FiloSottile/age), AWS CLI v2, TAR, coreutils, and the Aqari Pro backend source code in a private path such as `/opt/aqari-pro`.

Generate a recovery identity **offline**, on an independently controlled trusted machine:

```bash
age-keygen -o /secure-offline/recovery-identity.txt
age-keygen -y /secure-offline/recovery-identity.txt
```

Keep the **private** identity offline in a separate encrypted key backup accessible to authorized recovery operators. Do **not** upload it to the backup bucket, server, repository, or application. Put only the **public recipient** (starts with `age1`) in the server configuration. Losing the private identity means the encrypted backups cannot be decrypted.

Provision a dedicated private backup bucket: block public access; disable anonymous access and public ACLs; allow PutObject and HeadObject only to the backup service identity on the designated prefix; apply encryption at rest, audited IAM permissions, retention/Object Lock where appropriate, independent key management, alarms, replication and disaster-recovery planning. Make deletion/overwrite rights unavailable to the uploader.

Prepare a dedicated service user, owner-restricted configuration, and workspace. The example timer expects:

```text
/etc/aqari/backup.env                  # root-owned, mode 0600
/var/lib/aqari/backups/tmp             # aqari-backup-owned, mode 0700
/opt/aqari-pro/backend/scripts/...     # read-only app source
```

The environment file must be loaded as a service environment and must contain real values provided securely by the operator. **Never commit or paste these secrets into a chat**.

```dotenv
PGHOST=private-database-host
PGPORT=5432
PGDATABASE=aqari_database
PGUSER=backup_reader
PGPASSWORD=provided-from-secret-manager
AQARI_BACKUP_RECIPIENT=age1_public_recipient_from_offline_machine
AQARI_BACKUP_BUCKET=private-backup-bucket-name
AQARI_BACKUP_PREFIX=aqari-pro/postgresql
AQARI_BACKUP_WORKDIR=/var/lib/aqari/backups/tmp
AWS_DEFAULT_REGION=your-region
```

Use a restricted machine role or securely injected AWS credentials. For an S3-compatible provider, configure its endpoint through the supported AWS CLI environment/profile on the private server and verify `put-object`, `head-object` and AES256 behavior. The script deliberately does not silently downgrade encrypted-object requirements.

Run manually first **with test data only**:

```bash
sudo systemctl start aqari-offsite-backup.service
sudo journalctl -u aqari-offsite-backup.service --no-pager
```

After provisioning the service files under `/etc/systemd/system`, verifying paths/user/permissions, and completing a restore rehearsal, the operator may enable the daily timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now aqari-offsite-backup.timer
systemctl list-timers aqari-offsite-backup.timer
```

The example schedule is 02:15 server time, with up to 15 minutes of randomized delay. It has **not** been activated by the code changes.

## Recovery drill from the remote object

On an isolated recovery machine with a private recovery destination and the **offline identity** explicitly provided by a recovery operator:

1. Use a read-only retrieval identity to download a selected `.tar.age` object from the private bucket. Verify the expected object key, bucket and TLS endpoint.
2. Decrypt the age bundle into a private mode-0700 directory. Do not stream it into a publicly accessible folder.
3. Inspect the TAR member names; they must be exactly `database.dump`, `database.sha256`, `manifest.txt`. Extract into a new recovery directory without preserving unsafe owners.
4. Verify SHA-256, then run `backend/scripts/pg-restore-verify.sh` against a freshly provisioned **empty** `aqari_restore_*` database on an isolated server.
5. Restore the separately backed-up attachment objects and run `php artisan aqari:verify-attachments` with private, preferably read-only recovery storage credentials.
6. Perform read-only domain smoke tests (owners, contracts, collected dues, receipts, isolation and document access). Record the recovery point, elapsed restore time, object counts, and deviations.

A simple local decryption example **only after safely obtaining and authenticating the encrypted object**:

```bash
age -d -i /secure-offline/recovery-identity.txt \
  -o /private/rehearsal/backup.tar /private/rehearsal/backup.tar.age
tar -tf /private/rehearsal/backup.tar
tar -C /private/rehearsal/database -xf /private/rehearsal/backup.tar
bash backend/scripts/pg-restore-verify.sh \
  /private/rehearsal/database aqari_restore_rehearsal01
```

The recovery `database` directory must exist, be private, and be empty before extraction. Never restore into production as a test.

## Production gate and failure modes

This implementation does **not** configure a cloud bucket, activate the timer, supply secrets, encrypt/archive local or S3 attachment objects, deliver failure alerts, validate a real storage provider, or prove recovery RPO/RTO. Workstation/shell tracing must not log `PGPASSWORD` or cloud keys.

A remote size check detects missing or obviously truncated uploads; it is **not equivalent to downloading, decrypting and restoring from the actual object**. The CI tests perform decryption against a mock object store, not a real cloud account. Schedule routine independent restore drills and failure-alerting before accepting real customer data.
