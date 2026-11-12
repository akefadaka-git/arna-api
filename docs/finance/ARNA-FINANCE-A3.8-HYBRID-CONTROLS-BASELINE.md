# ARNA Finance A3.8 — Hybrid Controls Baseline

**Status:** Implemented controls baseline; operational proof outstanding  
**Architecture authority:** A1 / A2 Finance decisions  
**Implementation branch:** A3 release-candidate lineage

## 1. Security boundaries

- Finance remains the only authoritative general-ledger posting boundary.
- Tenant context is derived from authenticated server context; callers do not choose tenant IDs.
- Legal entity ownership is checked server-side for evidence and Finance entity operations.
- AI provider integration is disabled by default. No provider secret, prompt egress, or model call is active.
- AI evidence and proposal content is immutable in MySQL after creation; review is a one-way maker-checker transition.
- Outbox delivery uses lease tokens; stale or foreign tokens cannot acknowledge or retry another worker's claim.
- Unmapped subledger events are retained in a tenant-scoped quarantine inbox and never silently posted or discarded.
- No API route exposes an AI posting, payment, reversal, or period-close tool.

## 2. Observability

The integration-health endpoint reports tenant-scoped outbox counts, inbox counts, oldest pending/quarantined timestamps, and an explicit reconciliation state. It does not expose event payloads or credentials.

The endpoint is not a substitute for a source-domain reconciliation adapter. Until source systems provide authoritative control totals and event versions, the reconciliation state remains NOT_RECONCILED.

## 3. Deployment profiles

### Cloud profile
- Use managed database encryption at rest and TLS in transit.
- Store database, mail, SMS, bank, FX and AI credentials in the deployment secret manager; never in source control or application config files.
- Restrict database/network access to application and backup services.
- Keep provider egress disabled until approved endpoints, regions, data minimization, retention, and contract terms are reviewed.
- Emit audit and health telemetry without financial payloads or secrets.

### Self-hosted profile
- Keep ledger posting, calendar conversion, and deterministic validation local.
- Use a local secret store and OS-managed key permissions.
- Disable outbound AI/provider traffic unless explicitly configured by the Owner.
- Maintain encrypted, access-controlled backups outside the application host.
- Support offline intake only through authenticated, attributable, idempotent event envelopes; offline state cannot bypass posting authorization or accounting-period checks.

## 4. Backup, restore and key management gates

No production backup or restore drill has been executed by this implementation. Before A5:
1. Select and document database backup frequency and retention.
2. Set Owner-approved RPO/RTO targets; these are intentionally not guessed here.
3. Encrypt backups and test key recovery independently of the application host.
4. Perform a restore into an isolated environment and verify journal counts, debit/credit totals, audit continuity, outbox/inbox replay safety, and migration compatibility.
5. Test key rotation and revocation for application secrets and any future signing/encryption keys.
6. Record immutable evidence, operators, timestamps, and exact database/application SHAs.

## 5. Unresolved production gates

- Independent security review: NOT PERFORMED.
- Independent accounting-policy review: NOT PERFORMED.
- Cloud deployment-profile test: NOT RUN.
- Self-hosted deployment-profile test: NOT RUN.
- Backup/restore drill: NOT RUN.
- Key rotation/recovery drill: NOT RUN.
- Data residency and provider egress approval: NOT APPROVED.
- Source-domain control-total reconciliation: NOT IMPLEMENTED.

This document establishes controls and acceptance evidence requirements. It does not declare A3.8 closed or authorize A4/A5.
