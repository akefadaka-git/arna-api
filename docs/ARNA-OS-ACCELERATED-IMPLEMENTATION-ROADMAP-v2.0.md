# ARNA OS Accelerated Implementation and Release Roadmap

**Document ID:** ARNA-ROADMAP-002  
**Version:** 2.0  
**Status:** Owner-requested roadmap update; proposed master delivery structure  
**Date:** 2026-11-08  
**Canonical repository:** `akefadaka-git/arna-api`  
**Companion application repository:** `akefadaka-git/arna-app`  
**Supersedes for forward planning:** the compressed P0–P5/P0–P6 phase summaries used in recent progress discussions  
**Does not erase:** historical phase plans, existing domain specifications, prior approvals, or repository evidence.

---

## 1. Executive decision

ARNA OS will use an **incremental-release, parallel-workstream delivery model**.

The principal delivery sequence is:

- **P0–P2:** establish the verified scope, platform foundation, identity, security and governance.
- **P3:** deliver shared enterprise services.
- **P4:** implement business applications as complete, integrated vertical workflows.
- **P5:** deliver integration, analytics and ARNA Intelligence capabilities, with safe enabling work allowed to run in parallel.
- **P6:** harden, test and certify each release candidate.
- **P7:** deploy and operationally launch each approved release.
- **P8:** continuously expand and optimize the platform after initial production deployment.

**Deployment is P7.** It does not wait for every P8 enhancement. P7 is a controlled release gate, not permission to deploy automatically. Each production release requires the applicable P6 acceptance evidence and separate Owner authorization under A5.

This roadmap is a delivery structure, not a statement that any phase is complete. Existing completion percentages must be recalculated against repository evidence after A1/A2 reconciliation.

## 2. Delivery principles

1. **One platform, one canonical contract:** API, application, database, identity, tenancy and domain contracts must remain coherent across both repositories.
2. **Reuse before replacement:** inspect MASES and existing ARNA capabilities before implementing a new workflow. Preserve working behavior and avoid duplicate functionality.
3. **Vertical slices:** deliver complete business outcomes—data model, migration, API, authorization, UI, audit and tests—instead of isolated screens or endpoints.
4. **Parallelize independent work:** after shared contracts and dependencies are stable, independent domain workstreams may proceed concurrently.
5. **Security before exposure:** no module or AI capability may bypass identity, tenant isolation, permission checks, audit or data-governance controls.
6. **Incremental production releases:** release certified capabilities in controlled increments; unfinished capabilities remain unavailable or feature-disabled.
7. **Evidence-led status:** PASS, VERIFIED-CLOSED, PARTIAL, MISSING, UNRESOLVED, UNKNOWN and NOT RUN must be used precisely. A passing workflow is not proof of complete business functionality.
8. **Cost-aware execution:** prefer public GitHub workflows, free execution capacity and suitable self-hosted runners where feasible. No paid Actions billing, deposits, top-ups or paid-service purchase is a prerequisite in this plan.
9. **No implicit production authority:** documentation or implementation approval does not authorize merge to a protected baseline, production data changes or deployment unless that action is separately approved.

## 3. Master implementation phases

### P0 — Discovery, reconstruction and scope freeze

**Objective:** establish what exists, what is authoritative, what is reusable and what must be built.

Scope:
- Assess the current MASES system and its business functionality.
- Inventory the destination ARNA API and application repositories.
- Map existing modules, routes, screens, migrations, models, tests, workflows and open changes.
- Identify duplicated functionality, incomplete implementations, technical debt and integration risks.
- Establish the authoritative feature inventory, requirements, priorities and acceptance criteria.
- Record assumptions, external dependencies and unresolved Owner decisions.

Deliverables:
- Evidence-backed current-state inventory.
- MASES-to-ARNA capability mapping.
- Requirements and feature traceability matrix.
- Prioritized release candidate scope.
- Risk and dependency register.

Exit gate: A1 assessment/specification is reviewed; A2 read-only reconciliation confirms the repository baseline and unresolved gaps. No implementation is inferred from documentation alone.

### P1 — Unified platform foundation

**Objective:** provide a stable engineering and application foundation for all domain workstreams.

Scope:
- Laravel API and frontend application foundations, using the actual supported repository stacks.
- Canonical database and migration conventions.
- Domain boundaries, service contracts and shared API conventions.
- Amharic-first, mobile-first application shell and localization conventions.
- Shared design-system and accessibility foundations, targeting WCAG 2.2 AA.
- Environment configuration, secrets handling, test fixtures and CI workflows.
- Development, staging and production configuration separation.

Deliverables:
- Approved architecture and domain contracts.
- Reproducible setup and verification workflows.
- Migration/model consistency rules.
- Shared UI/API foundations.
- CI evidence on exact candidate commits.

Exit gate: clean-install/build and foundational tests pass on exact candidate SHAs; migration discovery and rollback expectations are documented.

### P2 — Identity, security and governance

**Objective:** ensure every user, request, tenant and privileged action is authenticated, authorized and auditable.

Scope:
- Canonical user identity and authentication lifecycle.
- Sanctum SSO and approved trusted subdomain behavior.
- Role- and permission-based authorization.
- Tenant and legal-entity isolation.
- MFA and session lifecycle where required by the approved security specification.
- Owner authority, explicit approval boundaries and privileged-action controls.
- Audit events, security logging, access review and fail-closed behavior.

Deliverables:
- Identity and SSO contract.
- Authorization/permission catalogue and enforcement.
- Tenant-isolation regression suite.
- Audit and privileged-action evidence.
- Security threat and control matrix.

Exit gate: authentication, authorization, tenant isolation, session and audit tests pass on exact candidate SHAs. No business workflow may be exposed with unresolved critical authorization gaps.

### P3 — Shared enterprise services

**Objective:** implement reusable services required by multiple business domains.

Scope:
- Organization and legal-entity structures required by domain workflows.
- Document and file storage, metadata, access and retention foundations.
- Notifications and communication infrastructure.
- Calendar and scheduling services.
- Shared search and reusable workflow primitives.
- Central administration and operational configuration.
- Common API resources, events, audit integration and UI components.

Deliverables:
- Shared-service contracts and ownership boundaries.
- API/UI integrations and domain-consumer examples.
- Permission and tenant-scope tests.
- Service-level failure and retry behavior.

Exit gate: each shared service has documented ownership, access controls, consumer contracts and exact-head regression evidence.

### P4 — Business applications and domain workflows

**Objective:** deliver the business functionality that makes ARNA OS operational.

Implement complete vertical workflows, prioritized by business value and dependencies:

1. **Shareholders and shareholder portal:** shareholder register, ownership and share balances, transfers where authorized, shareholder records, meetings, attendance, proxies, voting, resolutions and portal access.
2. **Finance and accounting:** chart of accounts, legal-entity books, ETB base currency, multi-entity accounting, journals, double-entry controls, receivables/payables as approved, loans, repayments, wallet/bank integrations, reconciliations and financial statements.
3. **People and HR:** employee records, identity linkage, roles, employment lifecycle and approved HR workflows.
4. **Organization and governance:** organizational structures, boards, committees, governance records, approvals, meetings and resolutions.
5. **Documents and records:** document lifecycle, classification, access, versioning, retention and links to business records.
6. **Communication and calendar:** internal communications, notices, events, scheduling and workflow notifications.
7. **Administration and public corporate site:** controlled administrative operations and approved public-facing corporate information.

Rules:
- Reconcile MASES behavior and data before replacing it.
- Do not duplicate existing canonical models, migrations or business rules.
- Keep financial postings balanced and traceable; do not treat wallet balances as a substitute for the accounting ledger.
- Keep legal-entity books separate while supporting approved consolidated reporting.
- Enforce shareholder voting by the applicable approved ownership/share rules, not by an assumed one-person/one-vote model.
- Every workflow must include authorization, audit, validation, error handling and regression tests.

Deliverables:
- Domain capability matrix and traceability to approved requirements.
- Complete API, data, UI and test slices.
- Migration and data-integrity evidence.
- Cross-domain integration tests.
- User acceptance scenarios.

Exit gate: the agreed R1/R2 business scope is complete enough for its release, with no critical data-integrity or authorization gaps. P4 as a whole may continue after R1.

### P5 — Integration, analytics and ARNA Intelligence

**Objective:** connect domains and introduce intelligence without weakening platform governance.

Scope:
- Cross-domain integration and event contracts.
- Enterprise reporting, dashboards, operational metrics and financial analytics.
- OCR and document extraction pipelines with review/provenance.
- ARNA AI architecture, knowledge services, retrieval and assistant capabilities.
- Data lineage, provenance, access filtering and auditability for AI outputs.
- Governed workflow automation and agent orchestration.
- Financial and operational decision-support capabilities.

ARNA AI is a distinct ARNA platform intelligence capability. It is not IROKA, and no assumption of shared public branding or unrestricted data access is permitted. Any IROKA integration must have separately defined and approved interfaces and boundaries.

Parallelization rule: architecture, data contracts, synthetic-data tests and isolated AI prototypes may proceed alongside P3/P4 once their prerequisites are defined. Production AI access or actions must wait for verified identity, authorization, tenant isolation, provenance, human-approval and safety controls.

Deliverables:
- Integration contracts and end-to-end test evidence.
- Metric definitions and reporting lineage.
- OCR extraction accuracy and human-review controls.
- ARNA AI architecture and capability-specific acceptance criteria.
- AI data-access, provenance, action and escalation policies.

Exit gate: each P5 capability is independently tested and approved for its intended release. P5 need not be entirely complete before the first core production release.

### P6 — Production hardening and acceptance

**Objective:** certify a specific release candidate for production.

Scope:
- Full API and frontend regression suites.
- Database migration, integrity, backup and restore verification.
- Authentication, authorization, tenant-isolation and security testing.
- Financial invariants and reconciliation tests.
- Integration, failure-recovery and idempotency tests.
- Performance, reliability and accessibility testing.
- User acceptance testing against approved scenarios.
- Monitoring, alerting, logging, incident response and rollback readiness.
- Release notes, operator runbooks and known-issue register.

Deliverables:
- Release-candidate SHA manifest for API and app.
- Test and workflow evidence with exact run links.
- Security and data-integrity sign-off.
- Migration and rollback plan.
- UAT acceptance record.
- Go/no-go recommendation and unresolved-risk register.

Exit gate: all release-blocking checks pass on the exact release candidate. Any exception requires explicit Owner disposition; unresolved critical security, data-integrity or recoverability findings block release.

### P7 — Deployment and operational launch

**Objective:** deploy a P6-certified release through a controlled, reversible production process.

Scope:
- Confirm production environment, DNS, TLS, runtime versions, configuration and secrets.
- Verify backup and restore points before data changes.
- Execute approved migrations in the documented order.
- Deploy API and frontend release artifacts.
- Verify authentication, SSO, tenant boundaries and critical business journeys.
- Run production smoke tests and observe monitoring/alerting.
- Conduct controlled onboarding and operational handover.
- Record release versions, SHAs, migration state, deployment time and rollback decision.

Deliverables:
- Production deployment record.
- Exact deployed API/app SHAs and release identifiers.
- Migration and smoke-test evidence.
- Monitoring and backup confirmation.
- Rollback/incident readiness record.
- Operational handover and post-release review.

Exit gate: production verification passes and Owner accepts the release. Deployment is not authorized merely because this roadmap is saved or a workflow passes.

### P8 — Post-deployment expansion and optimization

**Objective:** continuously improve ARNA OS without blocking the first safe production release.

Scope:
- Advanced ARNA AI capabilities and governed agents.
- Expanded domain workflows and additional business units.
- More integrations and automation.
- Advanced analytics and forecasting.
- Mobile/PWA enhancements and offline capabilities where approved.
- Performance, scalability, resilience and cost optimization.
- Continuous security, accessibility and operational improvements.

P8 is continuous. Each material capability follows the same specification, implementation, verification and release controls. It is not a reason to defer P7 deployment of already-certified capabilities.

## 4. Parallel workstream model

After P0–P2 contracts are stable, independent workstreams may proceed concurrently:

| Workstream | Primary focus | Main phase alignment |
|---|---|---|
| A — Finance | Accounting, loans, wallet, reconciliations | P4, P5 |
| B — Shareholders and governance | Ownership, meetings, proxies, voting, resolutions | P4 |
| C — People and organization | HR, organizational structures, legal entities | P3, P4 |
| D — Shared services | Documents, communications, calendar, notifications | P3, P4 |
| E — Application experience | Workspace, shareholder portal, admin, mobile-first UI | P1–P4 |
| F — Intelligence and analytics | OCR, reporting, ARNA AI, governed automation | P5, P8 |
| G — Platform assurance | Security, integration, CI, release evidence | P1–P7 |

Parallel work is allowed only when API contracts, data ownership, migration dependencies and access-control rules are explicit. Shared files or schema ownership must not be changed concurrently without coordination.

## 5. Incremental release strategy

### R1 — ARNA Core

Purpose: first usable, secure production release.

Candidate scope:
- Platform foundation, identity, SSO and tenant security.
- Administration and essential organization structures.
- A deliberately bounded set of complete, high-value shareholder and finance workflows.
- Essential document, notification and audit capabilities.
- Production monitoring, backup, rollback and operational controls.

R1 is not a prototype. Only capabilities that pass P6 are enabled in production. Unfinished modules remain disabled or inaccessible.

### R2 — ARNA Enterprise

Purpose: expand to the approved full enterprise operating scope.

Candidate scope:
- Broader shareholder and governance workflows.
- Expanded finance/accounting, HR and organization.
- Integrated document, communication and calendar workflows.
- Cross-domain reporting and approved integrations.

### R3 — ARNA Intelligence

Purpose: deliver advanced intelligence as a governed enterprise capability.

Candidate scope:
- ARNA AI assistant and knowledge retrieval.
- OCR/document intelligence.
- Advanced financial and operational analytics.
- Governed automation and agents.

R1, R2 and R3 are release groupings, not replacements for phase gates. Each release candidate must pass P6 and P7. A capability can move between release candidates only through documented scope control.

## 6. Indicative planning ranges

These are initial planning estimates, not commitments or evidence of actual progress. They assume parallel workstreams, stable requirements, timely Owner decisions and usable CI capacity.

| Phase | Indicative effort |
|---|---:|
| P0 — Discovery and scope freeze | 1–2 weeks |
| P1 — Platform foundation | 2–3 weeks |
| P2 — Identity and security | 2–3 weeks |
| P3 — Shared services | 2–4 weeks |
| P4 — Business workflows | 4–8 weeks |
| P5 — Integration, analytics and intelligence | 3–6 weeks, partly parallel |
| P6 — Release hardening and acceptance | 2–3 weeks per release candidate |
| P7 — Deployment and launch | 1–2 weeks per release candidate |
| P8 — Expansion | Continuous |

These ranges overlap and must not be summed as a sequential schedule. A credible calendar baseline requires A1/A2 inventory, dependency analysis, work sizing, actual CI capacity and release-scope decisions. No prior April–June 2027 estimate is reaffirmed by this document.

## 7. Governance and stage gates

The Owner-governed A1–A5 control model remains in force:

| Gate | Meaning | Required evidence |
|---|---|---|
| A1 | Assessment, requirements and specification freeze | Scope, inventory, acceptance criteria, risks and dependencies |
| A2 | Read-only repository reconciliation | Exact SHAs, file/feature mapping, conflicts, existing behavior and gap register |
| A3 | Authorized implementation | Approved scope, branch/PR, affected files and implementation evidence |
| A4 | Verification and closure review | Exact-head tests, workflow runs, security review, migration/model consistency and closure decision |
| A5 | Separately authorized deployment | Explicit deployment approval, release candidate, preflight, rollback and production evidence |

A1/A2 do not authorize implementation. A3 does not authorize merge or deployment. A4 does not authorize deployment. A5 remains separate and requires explicit Owner approval.

## 8. Definition of done for every capability

A capability is not complete until all applicable items are evidenced:

- Approved requirement and acceptance criteria.
- Canonical domain/data ownership and no duplicate model or migration.
- Safe migration, indexes, constraints, rollback and data-integrity behavior.
- API validation, authorization, tenant scope and consistent error semantics.
- Frontend workflow, loading/empty/error states and responsive behavior.
- Auditability and notification behavior where required.
- Unit, feature, integration and regression tests.
- Exact candidate SHA and relevant successful workflow runs.
- Documentation, operational notes and known limitations.
- A4 review and explicit status classification.

## 9. Reporting and status rules

Every progress report must identify:
- Repository, branch and exact commit SHA for each relevant codebase.
- PRs, workflow run links, test suites and migration status.
- Affected files and capability-to-phase mapping.
- Status labels: PASS, VERIFIED-CLOSED, PARTIAL, MISSING, UNRESOLVED, UNKNOWN or NOT RUN.
- Phase completion based on verified acceptance criteria, not activity counts.
- Release scope, blockers, risks, dependencies and immediate next action.
- Production readiness separately from implementation progress.

Do not carry forward the previously quoted 46% overall estimate or prior P0–P5 phase percentages as official figures. Re-baseline them only after A1/A2 reconciliation against the current destination repositories.

## 10. Repository and change-control policy

- Destination repositories are authoritative: `akefadaka-git/arna-api` and `akefadaka-git/arna-app`.
- This document is the canonical master delivery roadmap in the API repository. The application repository should reference this roadmap rather than maintain a divergent phase sequence.
- Existing MASES/ARNA functionality and unrelated changes must be preserved.
- Do not rename, delete or silently supersede historical specifications; record explicit crosswalks where necessary.
- Do not mark a phase complete based on this document.
- Do not merge, deploy, modify production data or run production migrations under this roadmap alone.
- Any changes to phase scope, release criteria or Owner-controlled decisions must be recorded with rationale and evidence.

## 11. Immediate next steps

1. **A1:** assess the current API and application repositories against this roadmap; produce a complete feature inventory, MASES reuse map, risks and dependencies.
2. **A2:** perform read-only reconciliation against exact destination branch SHAs; map existing work to P0–P8 and R1–R3.
3. Produce the evidence-backed release scope for R1, with explicit exclusions and acceptance criteria.
4. Establish the dependency graph and parallel workstream boundaries.
5. Present a re-baselined phase status, realistic calendar and release recommendation for Owner review.
6. Begin A3 only after the relevant scope and implementation authorization are approved.

---

**Document control:** This file records the proposed accelerated delivery structure requested by the Owner. Saving this roadmap does not itself approve code implementation, PR merging, production data changes or deployment.
