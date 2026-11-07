# ARNA Wallet and Loan — Production Readiness A1 Assessment

- **Assessment date:** 2026-11-07
- **Assessment gate:** A1 — read-only production-readiness assessment
- **Repository:** `akefadaka-git/arna-api`
- **Baseline main SHA:** `b2fe8a64ab6ad07678e8b686e08d9b4a12e954bf`
- **Related remediation PR:** [#91](https://github.com/akefadaka-git/arna-api/pull/91)
- **Remediation head:** `e38496d84411f6b8237d6b1a7bf7ded7407b4567`
- **Merge commit:** `b2fe8a64ab6ad07678e8b686e08d9b4a12e954bf`
- **Authority boundary:** Read-only inspection and documentation only. No production access, data changes, source-code changes, merge, or deployment.

## 1. Executive decision

**Production readiness: NOT ESTABLISHED. Production deployment: NOT APPROVED.**

PR #91 closes the two identified code-level blockers: the Symfony dependency advisories and the reproduced MySQL Loan repayment concurrency deadlock. Its exact-head clean-install MySQL workflow passed, including the independent-process concurrency regression and mandatory Composer audit. Additional Admin audit/security and tenant-membership authorization workflows also passed.

Those results establish meaningful candidate-code verification. They do not establish that the live production environment is configured, recoverable, monitored, secure, or safe to receive financial transactions. No production database, Plesk control panel, live secrets, backup, restore, queue worker, or deployment target was inspected in this A1 assessment.

## 2. Verified repository and CI evidence

| Evidence | Result | Reference |
|---|---|---|
| PR #91 merged | VERIFIED | [PR #91](https://github.com/akefadaka-git/arna-api/pull/91) |
| Main SHA | VERIFIED | `b2fe8a64ab6ad07678e8b686e08d9b4a12e954bf` |
| Clean-install MySQL workflow #1258 | PASS | [Run #1258](https://github.com/akefadaka-git/arna-api/actions/runs/42320910380) |
| Independent MySQL Loan repayment concurrency regression | PASS | Step 44 of run #1258 |
| Composer security audit | PASS | Step 45 of run #1258 |
| Fresh migrations and second migration/idempotence pass | PASS | Steps 23, 39–43 of run #1258 |
| Admin audit/security workflow #22 | PASS | [Run #22](https://github.com/akefadaka-git/arna-api/actions/runs/42388415871) |
| Tenant membership/authorization workflow #615 | PASS | [Run #615](https://github.com/akefadaka-git/arna-api/actions/runs/42388415906) |

The clean-install MySQL job reports all listed steps successful. The three Symfony components were updated to v7.4.23: `symfony/http-foundation`, `symfony/http-kernel`, and `symfony/routing`.

**Scope limitation:** these workflows ran in isolated CI environments. They do not validate production data, grants, database version/configuration, network controls, secrets, or operational services.

## 3. Source-level production configuration findings

### 3.1 Runtime and environment configuration

Inspected:
- `composer.json` — PHP requirement is `^8.3`; Laravel requirement is `^12.0`.
- `.env.example` — development-oriented defaults include `APP_ENV=local`, `APP_DEBUG=true`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, and log mail.
- `config/database.php` — MySQL is supported; the default connection remains environment-driven and falls back to SQLite.
- `config/queue.php`, `config/cache.php`, `config/session.php` — database/Redis options exist, but the production-selected drivers and live service health cannot be inferred from source.

Disposition:
- **VERIFIED:** the application has PHP 8.3/Laravel 12 requirements and supports MySQL.
- **MISSING:** evidence of production environment values and secure production overrides.
- **UNKNOWN:** actual production PHP extensions, MySQL/MariaDB version, TLS settings, database host restrictions, Redis/cache service, queue worker configuration and session-cookie settings.
- **NOT APPROVED:** do not use `.env.example` as a production configuration. In particular, production must not run with debug enabled or a development SQLite fallback.

### 3.2 Deployment automation and release process

The current `.github/workflows/` directory was inspected. It contains CI/regression and repository-assessment workflows, including `arna-clean-install-mysql.yml` and `arna-nightly-assessment.yml`. No production deployment workflow was found in the inspected workflow directory. No verified deployment run, release artifact, rollback workflow, or environment approval record was provided.

Disposition:
- **VERIFIED:** automated regression workflows exist.
- **MISSING:** a verified, controlled production deployment/rollback mechanism in the inspected workflow directory.
- **NOT RUN:** deployment rehearsal and production smoke test.
- This is not a request to create or execute a deployment workflow; that requires a separately approved release design.

### 3.3 Existing production read-only SQL preflight

Existing file: `docs/operations/arna-production-readonly-preflight.sql`.

The script is explicitly metadata-only and read-only. It inspects the authenticated database identity, grants, and shareholder/alias/sequence table metadata in schema `arna`. It warns operators to stop if the inspected account has application-table SELECT or write/admin grants.

Disposition:
- **VERIFIED:** a limited read-only metadata preflight exists.
- **PARTIAL:** its scope is limited to shareholder-related tables and does not establish the full Wallet/Loan production schema, all application-role grants, database engine/version, backup state, or operational health.
- **NOT RUN:** no live execution was performed. Do not run it until the production operator confirms the intended schema and dedicated read-only account.

### 3.4 Repository documentation integrity issue

The current `README.md` states that a system-principal candidate associated with PR #13 remains open and unmerged. Live GitHub metadata shows PR #13 is closed and merged, and its title/body describe a P1.3 Governance documentation contract, not the system-principal implementation described by the README. The README also references an architecture document and test path that were not found at the stated paths in the default branch during this assessment.

Disposition:
- **CONTRADICTORY / UNRESOLVED:** the README's PR #13 status and subject attribution do not match live PR metadata.
- **MISSING:** the cited architecture/test artifacts at the README paths.
- This discrepancy does not invalidate PR #91's concurrency/security verification, but it weakens repository-level evidence integrity and must be reconciled before a platform-wide production certification.

## 4. Production gate register

| Gate | Status | Evidence required to close |
|---|---|---|
| PR #91 dependency security | VERIFIED / PASS | Exact-head Composer audit run #1258 |
| Loan repayment transaction concurrency | VERIFIED / PASS | Independent-process MySQL test in run #1258 |
| Clean MySQL migrations | VERIFIED / PASS | Fresh migration and idempotence steps in run #1258 |
| Admin audit/security regressions | VERIFIED / PASS | Workflow #22 |
| Tenant membership/authorization regressions | VERIFIED / PASS | Workflow #615 |
| Production PHP runtime/extensions | UNKNOWN | Read-only Plesk/runtime inventory |
| Production DB engine/version/configuration | UNKNOWN | Read-only DB metadata and configuration review |
| Production application DB grants | MISSING | Full grants review for actual app account; least privilege and table-specific restrictions evidenced |
| Production Wallet/Loan schema parity | MISSING | Read-only schema/index/constraint comparison against approved migrations |
| Production secrets and environment settings | UNKNOWN | Secure operator-side review; never disclose secret values in reports |
| Backup freshness and retention | MISSING | Timestamped backup evidence, retention policy and backup integrity check |
| Restore/recovery rehearsal | NOT RUN | Successful isolated restore to a non-production target with recorded RPO/RTO |
| Queue workers and failed-job handling | UNKNOWN | Worker process/supervisor health, retry/timeout settings and failed-job alert evidence |
| Scheduler/cron operation | UNKNOWN | Scheduler registration and successful scheduled-task evidence |
| Cache/session/mail services | UNKNOWN | Actual production driver selection, connectivity and secure cookie/TLS configuration |
| Monitoring, audit alerts and incident response | MISSING | Health/error/queue/DB monitoring, alert routing, incident owner and runbook |
| Performance/load and financial contention | NOT RUN | Approved workload model, load test and database contention evidence |
| Deployment/rollback rehearsal | NOT RUN | Exact-SHA staging deployment, migration safety and tested rollback/forward-fix plan |
| Post-deployment financial smoke tests | NOT RUN | Authorized non-destructive checks and controlled test transaction/reconciliation |
| Regulatory/payment integration authorization | NOT APPROVED | Applicable regulatory, banking/provider and Owner approvals before enabling live integrations |

## 5. Immediate blockers and risks

1. **No production environment evidence.** Production Plesk/runtime and database configuration have not been inspected in this A1 pass.
2. **No recovery evidence.** Backup freshness, restore success, recovery objectives and rollback are unverified.
3. **No operational evidence.** Queue, scheduler, monitoring, alerting and incident handling remain unknown or missing.
4. **No release evidence.** The inspected workflow set contains verification workflows but no verified production release/rollback run.
5. **Documentation inconsistency.** README PR #13/system-principal statements are contradicted by live PR metadata and missing referenced paths.
6. **Regulatory boundary.** Passing application tests does not authorize real-money payment integrations or financial operations.

## 6. Recommended A2 read-only evidence collection

A2 should remain read-only and use a dedicated operator-provided account. No credentials or secrets should be sent through chat or committed to Git.

1. Obtain a Plesk inventory: domain/document root, PHP 8.3 runtime, required extensions, PHP-FPM/web-server settings, HTTPS/TLS certificate, storage permissions and configured cron entries.
2. Obtain database metadata: engine/version, schema identity, charset/collation, timezone, SQL mode, transaction isolation, InnoDB status summary, connection limits and backup metadata.
3. Inspect grants for the actual application DB account and migration/admin account separately. Confirm least privilege and that application credentials cannot perform schema/user administration.
4. Compare production Wallet/Loan tables, columns, indexes, foreign keys and migration ledger with the reviewed `main` schema. Do not query or export member financial rows for this assessment.
5. Verify backup schedule, latest successful backup, retention and encryption metadata. Restore only to a separate non-production target.
6. Verify queue worker/supervisor state, failed-job handling, scheduler/cron execution, cache/session/mail configuration and monitoring/alert destinations.
7. Produce a deployment/rollback plan for an exact SHA, including migration compatibility, maintenance window, pre-deployment backup, post-deployment checks and stop/rollback criteria. Do not execute it.

## 7. Gate decision and authorization boundary

- A1 read-only repository/source assessment: **COMPLETE**.
- A1 production environment inspection: **BLOCKED / NOT AVAILABLE**.
- A2 production evidence collection: **READY TO PLAN; requires operator-side read-only evidence/access**.
- A3 implementation or release automation changes: **NOT AUTHORIZED / NOT RUN**.
- A4 deployment rehearsal: **NOT RUN**.
- Production deployment: **NOT AUTHORIZED / NOT RUN**.
- Live financial transactions/integrations: **NOT AUTHORIZED**.

No production credentials, database data, secrets, configuration, or deployment state were accessed or changed during this assessment.

## 8. Reporting cadence

The repository already has `.github/workflows/arna-nightly-assessment.yml`, scheduled at 21:00 UTC (00:00 Africa/Addis_Ababa), which generates daily repository progress reports. This existing schedule should be reused for routine progress snapshots; do not create a duplicate daily automation for the same report. The nightly repository assessment is not a substitute for operator-provided production evidence and must not mark production gates closed without it.

**Next action:** Owner/operator provides or enables a safe read-only A2 evidence path for Plesk and production database metadata. Until A2 is evidenced and later release gates are approved, keep production deployment blocked.
