# ARNA AI A1 — Deployment, Operations & Observability

**Document ID:** ARNA-AI-A1-008  
**Version:** 1.0.0  
**Status:** PROPOSED — OWNER APPROVAL PENDING

## 1. Deployment principles

- Preserve provider-independent ARNA domain logic.
- AI availability must not be a dependency for deterministic ARNA business operations.
- Keep provider egress disabled until explicitly approved.
- Support controlled cloud and self-hosted/hybrid profiles.
- Separate development, test, staging and production credentials and data.
- Never place provider keys, tokens or secrets in source control or client-side bundles.
- Do not assume a particular model host, vector database or cloud vendor.

## 2. Logical deployment components

| Component | Deployment consideration |
|---|---|
| ARNA API / AI control plane | Authenticated server-side execution; existing API host and lifecycle |
| Agent runtime | Isolated process/service boundary as justified by A2 load and security assessment |
| Model Gateway | Outbound adapter with endpoint allowlist, quota and kill switch |
| Knowledge ingestion | Controlled asynchronous processing; source and tenant isolation |
| Retrieval index | Derived data store with source pointers and permission metadata |
| File/OCR processing | Isolated parser workers; untrusted file sandboxing and resource limits |
| Queue/workers | Idempotent jobs, bounded retries, dead-letter/quarantine and observability |
| Audit/telemetry | Restricted access, redaction, retention and integrity controls |
| ARNA App | Existing frontend build and deployment conventions; AI UI behind authorization |

The logical components do not require a microservice deployment. Physical topology is an A2/A3 decision.

## 3. Deployment profiles

### 3.1 Self-hosted / local profile
- Core ARNA identity, tenant checks and deterministic domain APIs operate locally.
- Provider traffic is disabled unless an approved adapter and endpoint are configured.
- Local model hosting is optional and not selected in A1.
- Use OS-managed secret permissions and encrypted backups.
- No local mode bypasses authorization, audit or approval.

### 3.2 Cloud / managed profile
- Use TLS in transit and managed encryption at rest.
- Restrict network access to required application, database, queue and backup services.
- Store credentials in a secret manager; rotate and revoke.
- Approve provider region, retention, training-use terms, subprocessors and data residency before prompt egress.
- Minimize telemetry and avoid raw financial/personal prompt payloads in logs.

### 3.3 Hybrid profile
- Keep authoritative ARNA records and deterministic business rules in ARNA-controlled systems.
- Allow only approved inference/processing services to operate externally.
- Apply data classification, minimization, encryption, region and retention policy to every outbound request.
- Provide a provider outage path that does not interrupt core domain operations.

## 4. Provider approval gate

Before any external model call:
1. Identify provider, model and endpoint.
2. Review contract, data processing terms, training use, retention, region and subprocessors.
3. Classify permitted data and redact/minimize payloads.
4. Approve credential custody, network egress and key rotation.
5. Define usage quota, cost ceiling and alert threshold.
6. Complete privacy, security and legal review as applicable.
7. Obtain explicit Owner authorization.
8. Test deny-by-default configuration and egress kill switch.

No provider selection, paid subscription or expenditure is authorized by A1.

## 5. Secrets and cryptographic controls

- Never commit provider credentials or secrets.
- Never expose secrets to App/browser clients or model prompts.
- Scope credentials per environment and provider.
- Use least privilege and rotation/revocation.
- Separate runtime credentials from Owner/Board/auditor/signatory identities.
- Do not allow an application runtime key to impersonate a corporate signatory.
- Record key lifecycle evidence without logging secret values.

## 6. Observability

Record structured metadata:
- correlation/request/task ID;
- actor and tenant context;
- agent/model/adapter and version;
- task-template and policy version;
- source IDs/versions and retrieval freshness;
- tool ID/version and authorization outcome;
- latency, token/usage and quota metadata;
- approval and execution lineage;
- refusal, timeout, retry, fallback and incident events.

Avoid raw sensitive prompts and full document contents in ordinary logs. Access to AI audit data must itself be permissioned and audited.

## 7. Operational metrics

| Category | Metric examples |
|---|---|
| Reliability | Request success/error rate, provider timeout, queue age, retry and dead-letter rate |
| Quality | Citation correctness, grounded answer rate, abstention, retrieval relevance, task completion |
| Security | Denied access, cross-tenant test failures, tool-policy violations, prompt injection alerts |
| Language | Amharic/English evaluation scores, OCR field accuracy, translation review findings |
| Cost | Usage by tenant/agent/model, quota consumption, cost ceiling alerts |
| Operations | Index freshness, ingestion backlog, source deletion propagation, kill-switch status |

Metrics must not reveal protected business content. Define numeric SLOs and alert thresholds only after workload baseline and Owner approval.

## 8. Resilience and graceful degradation

- Core ARNA workflows continue if AI, retrieval or a provider is unavailable.
- Use bounded retries, circuit breakers and explicit timeouts.
- Never silently route confidential context to an unapproved fallback provider.
- Preserve proposal state and idempotency during retries.
- Queue and quarantine failed ingestion/tool events with traceable status.
- Disable an affected agent/provider/tool independently where feasible.

## 9. Backup, recovery and continuity

Before A5, define and test:
- backup scope for configuration, agent definitions, audit, source metadata and derived indexes;
- retention and legal hold;
- Owner-approved RPO/RTO;
- encryption and key recovery;
- restore of source references and agent/tool versions;
- index rebuild from authoritative sources;
- queue replay and idempotency;
- incident and provider-outage runbooks.

Do not invent RPO/RTO or claim a restore drill has passed without evidence.

## 10. Cost and resource controls

- Per-user, tenant, agent and provider quotas.
- Max request size, context size, output size, concurrency and execution time.
- Budget ceilings and alerts before paid usage.
- No uncontrolled recursive agent loops.
- No unbounded tool retries or bulk ingestion.
- Owner approval before any paid provider or hosting commitment.

## 11. Deployment gate

This A1 document does not authorize environment configuration, secrets, DNS, SSO changes, provider activation, migration, deployment or production traffic. A5 remains a separate Owner-approved stage.
