# Aqari Pro — Owner V1 Release Gates (Go / No-Go)

**Status: NO-GO / not yet production-certified.** This is a test and operations acceptance record, not a claim that deployment exists.

The first release serves property owners only. Agency property-management workflows and the live Saudi Ejar integration are explicitly out of scope.

## 1. Automated evidence required from exact release SHA

| Gate | Automated evidence | Release rule |
|---|---|---|
| Laravel domain rules and owner isolation | `Laravel API checks` succeeds for the exact proposed SHA | Must pass |
| RTL owner portal, desktop and mobile | Playwright owner workflow within Laravel API checks | Must pass |
| Private document validation and ClamAV fail-closed logic | Laravel Feature tests including malware scan exit codes | Must pass |
| PHP + MySQL container, migrations and HTTP security | `Backend container validation` succeeds for the exact SHA | Must pass |
| Parallel receipts, lease conflict and bounded API reads | Container workflow runs the concurrent finance and performance scripts | Must pass |
| Encrypted backup/isolated restore | Container workflow runs `ci-backup-restore-drill.sh` | Must pass |

**Never treat a successful earlier SHA, a canceled workflow, a queued job, or a script's presence in the repository as a passing gate.** Record exact workflow run URLs and commit SHA before release approval.

## 2. Independent staging acceptance (not satisfied by GitHub Actions alone)

| Gate | Evidence needed | Current status |
|---|---|---|
| Staging deployment on a real isolated host | HTTPS URL, deploy SHA, successful browser login, health endpoint, safe rollback | **Not verified** |
| TLS / proxy / host hardening | Certificate, redirect, HSTS decision, response headers and firewall review | **Not verified** |
| ClamAV signature freshness | Real `freshclam` update and `check-clamav-readiness.sh` inside the running app container | **Not verified** |
| SMTP account recovery | Password-reset email received, valid token flow, SPF/DKIM/DMARC checked | **Not verified** |
| Owner separation and documents | Two separate staging owner accounts; upload/preview/download/delete and denied cross-owner access | **Not verified** |
| Staging financial concurrency | `staging-receipt-concurrency.cjs` on staging Apache/MySQL, with actual results preserved | **Not verified** |
| Staging API performance | `staging-read-performance.cjs` with realistic synthetic record volume and agreed p95/SLO | **Not verified** |
| Encrypted external backups | External storage destination, rotation/retention, alerting and restoration on separate host | **Not verified** |
| Monitoring and incident response | Uptime, logs, alerts, incident contact, rollback/restore procedures | **Not verified** |
| Privacy/legal and accounting review | Personal-data policy, access rules, receipt/refund and accounting acceptance | **Not verified** |
| Product owner UAT | Signed Arabic RTL and mobile walkthrough with documented defects resolved | **Not verified** |
| Authorized release decision | Named release approver, date, SHA and explicit Go/No-Go record | **Not verified** |

## 3. Known scope restrictions

- GitHub Pages demonstration is **not** the Laravel/MySQL production application.
- The deployed production URL and hosting credentials have not been verified.
- No real Ejar integration is active; do not label its workflows officially connected.
- Do not add agency-manager roles to Owner V1 unless scope is deliberately changed.
- Do not merge the draft pull request into `main` until the above gates are accepted.

## 4. Evidence record template

| Field | Value |
|---|---|
| Release candidate SHA | Pending |
| CI Laravel run URL and outcome | Pending |
| CI container run URL and outcome | Pending |
| Staging URL and deployment time | Pending |
| ClamAV real readiness outcome | Pending |
| Concurrency and API performance report | Pending |
| External backup restore drill reference | Pending |
| Security/privacy reviewer | Pending |
| UAT owner approval | Pending |
| Go/No-Go decision and date | **NO-GO** |

A release must not be marked "ready" by counting commits or assigning completion percentages without passing the evidence gates.
