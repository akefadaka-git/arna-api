# ARNA AI A1 — Acceptance & Verification Plan

**Document ID:** ARNA-AI-A1-009  
**Version:** 1.0.0  
**Status:** REVIEW — ACCEPTANCE CRITERIA PROPOSED

## 1. Purpose

Define objective evidence required to accept the A1 architecture package and the later A2/A3/A4 stages. This document is not a test execution report.

## 2. A1 deliverable acceptance

| ID | Deliverable | Acceptance evidence | Current status |
|---|---|---|---|
| A1-01 | Architecture specification | Goals, non-goals, context, layers, boundaries, NFRs, decisions and limitations | PREPARED |
| A1-02 | Approved decision reconciliation | Inherited approvals separated from proposals; sources and unresolved conflicts recorded | PREPARED; some canonical source links pending |
| A1-03 | Agent catalogue | Nine agents with ownership, tools, scope and exclusions | PREPARED |
| A1-04 | Knowledge/RAG governance | Knowledge classes, permission model, provenance, lifecycle and injection defense | PREPARED |
| A1-05 | Language architecture | Amharic-first, English support, Unicode, calendar, OCR and evaluation | PREPARED |
| A1-06 | Security/governance/autonomy | Identity, RBAC, tenant/entity, risk levels, approval, audit and threat model | PREPARED |
| A1-07 | Integration contracts | API, App, domains, model gateway, failure and versioning contracts | PREPARED |
| A1-08 | Deployment/observability | Hybrid profiles, egress, secrets, metrics, resilience, recovery and cost controls | PREPARED |
| A1-09 | Verification plan | A1/A2/A3/A4/A5 exit criteria and evidence | PREPARED |
| A1-10 | Owner review record | Explicit approve/amend/reject/defer decisions and freeze record | PENDING |

A1 is not complete until the Owner has reviewed the package and recorded explicit decisions on the review record.

## 3. A2 — Read-only repository reconciliation (separate approval)

A2 must inspect both destination repositories without modifying them.

### API
- exact main SHA, active branches, PRs and relevant workflow evidence;
- identity, Sanctum, tenant context, RBAC and authorization middleware;
- domain service boundaries and API routes;
- Finance AI implementation, proposal lifecycle and provider disabled state;
- Calendar, D08, Governance, Documents, Communication and other source contracts;
- audit/security events and file confidentiality;
- database schema, migrations, indexes and queue/worker design;
- AI packages, provider adapters, model configs and egress paths;
- existing tests and exact-head CI results.

### App
- exact main SHA and active branches/PRs;
- authentication and SSO behavior; confirm AI host is not assumed stateful;
- navigation, locale, calendar and theme contracts;
- AI-related screens, stores, API clients and route guards;
- source citation, proposal review and approval UI patterns;
- accessibility and Amharic rendering constraints.

### A2 output
- file-level inventory and exact SHAs;
- existing capability map: VERIFIED / PARTIAL / MISSING / UNRESOLVED / UNKNOWN / NOT RUN;
- contract mismatch and security gap register;
- provider/data egress status;
- test/workflow evidence tied to exact commit;
- proposed A3 dependency sequence.

A2 is read-only: no commits, branches, edits, migrations, PR changes, merges or deployments.

## 4. A3 — Implementation acceptance (separate approval)

Implementation must be split into independently reviewable packages:
1. AI Core/control plane and policy enforcement.
2. Model Gateway with provider disabled by default.
3. Knowledge source registry and permission-aware retrieval.
4. Agent registry/runtime and first read-only agents.
5. Evidence/citation and proposal lifecycle.
6. Amharic-first AI interaction and document intelligence foundations.
7. Domain-specific tools and approved workflows.
8. Observability, quotas, kill switches and hybrid readiness.

Each package requires a scoped branch, exact base/head SHA, affected files, migration impact, tests, security review and no unrelated changes.

## 5. A4 — Verification and acceptance (separate approval)

### Security hard blockers
- cross-tenant and cross-entity access denied;
- record confidentiality and permission revocation enforced;
- prompt injection cannot change system policy or invoke tools;
- unauthorized tools cannot be called;
- no self-approval or maker-checker bypass;
- no financial posting/payment/period-close capability for AI;
- no direct domain database writes;
- no secret exposure or unapproved provider egress;
- citations cannot reference nonexistent or unauthorized sources;
- audit lineage is attributable and tamper-resistant.

### Functional acceptance
- authorized source retrieval with correct citations;
- explicit abstention on missing/conflicting evidence;
- proposal creation, review and rejection lifecycle;
- domain tool authorization and idempotency;
- agent isolation and version rollback;
- Amharic and English workflow behavior;
- graceful degradation when model or retrieval is unavailable.

### Quality acceptance
- exact-head unit, feature, integration and security tests;
- Amharic/English evaluation sets with reviewed expected answers;
- OCR field accuracy with human-review thresholds;
- latency, reliability and cost tests under approved workload;
- App keyboard, screen-reader, responsive and WCAG 2.2 AA review.

No numeric quality or latency target is accepted without a defined dataset, sample size, rubric and evidence.

## 6. A5 — Deployment (separate approval)

A5 requires explicit Owner approval and verified:
- provider/hosting approval and cost ceiling;
- production secrets and endpoint allowlist;
- data residency/retention/legal review;
- backup/restore and incident runbooks;
- production access and authorization checks;
- monitoring, quotas, alerts and kill switch;
- release artifact, migration and rollback plan;
- user training and support ownership.

## 7. Evidence labels

Use only:
- **VERIFIED** — exact evidence inspected and criteria passed.
- **PARTIAL** — some criteria pass; remainder identified.
- **MISSING** — required artifact or capability absent.
- **UNRESOLVED** — conflicting or insufficient authoritative evidence.
- **UNKNOWN** — not established by available evidence.
- **NOT RUN** — verification not executed.
- **NOT AUTHORIZED** — work outside current stage.

A percentage must be checklist-weighted and must never override a security, authority or legal hard blocker.

## 8. A1 exit rule

A1 closes only after:
- all nine technical/functional documents are present and internally consistent;
- inherited decisions are traceable and not misrepresented;
- unresolved items are visible and accepted or assigned;
- Owner explicitly approves the exact version and scope;
- the A1 freeze record is completed.

Approval does not authorize A2, A3, A4 or A5.
