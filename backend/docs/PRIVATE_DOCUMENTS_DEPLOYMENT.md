# Aqari Pro — Private Document Storage Deployment Checklist

**Scope:** Laravel staging service only. GitHub Pages remains a local-only demonstration and must never store customer documents.

## 1. Mandatory file scanning

Install and manage ClamAV on the **private API server**:

```bash
sudo apt-get update
sudo apt-get install -y clamav clamav-freshclam
clamscan --version
```

Ensure antivirus signature updates are operational and monitored. Configure:

```dotenv
AQARI_CLAMAV_BINARY=clamscan
AQARI_CLAMAV_TIMEOUT_SECONDS=30
```

The attachment upload service checks the server-side temporary uploaded file **before persistence**. Exit 0 allows upload; exit 1 rejects the document; any missing scanner, timeout, or other error returns HTTP **503** without persisting the upload. There is no production bypass flag.

Test both a clean file and an **EICAR test sample** in an isolated test environment, and verify scanning outages block uploading. Never use an unknown customer file for malware experiments.

## 2. Private storage choice

The default is `AQARI_ATTACHMENT_DISK=local`: Laravel 12's private `local` disk outside the publicly served web root.

For an external private AWS-compatible bucket use:

```dotenv
AQARI_ATTACHMENT_DISK=s3
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
```

Install the Laravel-compatible S3 Flysystem adapter on the private server. Use a dedicated private bucket with **Block Public Access**, limited IAM `GetObject/PutObject` for the required prefix, HTTPS-only access, default encryption (SSE-KMS or equivalent), an audited key policy, versioning as appropriate, lifecycle/retention policy and access logs.

**Do not set a public-read ACL, public bucket policy or public storage URL.** The system issues authenticated, application-proxied downloads only and does not create public or presigned download URLs. The code's `private` object visibility is not a substitute for enforceable bucket encryption and access policy.

Each attachment records `storage_disk` and `storage_path` internally. Changing the configured disk affects new uploads only; old attachments remain on the original disk for later downloads. Paths and disk identifiers are hidden from API JSON.

## 3. Authorization and audit

The owner is resolved using the authenticated user and the linked property/contract. Upload/list/download authorization must return 404 for another owner's records.

The `attachment_events` append-only table records `uploaded` and `download_requested` events with the authenticated actor and timestamp. A download request event is logged before response streaming; it does **not** prove the browser finished receiving the entire file. Production audit-log retention, immutable archiving and security alerting remain outstanding.

## 4. Backup and recovery requirements

A database backup alone does **not** back up attachments. Plan coordinated PostgreSQL and object-storage backups, encrypted off-site copies, validated retention, documented disaster-recovery objectives and periodic restoration drills. Verify restored records point to existing objects, have intact owner permissions, and are still downloadable through authenticated routes. Backups containing customer documents must remain private.

## 5. Production gate

Before collecting sensitive customer identity, title-deed or contract documents, complete real HTTPS staging deployment, MFA and account recovery, storage policy/IAM review, malware signature monitoring, quotas and retention limits, privacy/legal review, observability, backup-and-restore rehearsal, and external browser/security tests.

Unit and integration tests using fake disks/scanners do **not** confirm a real S3 account, ClamAV daemon, retention policy or a production host.
