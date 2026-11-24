# ARNA ECC — 30-Domain KPI Readiness & Ownership Register

- **Record ID:** ARNA-ECC-A3.1-REG-001
- **Version:** 1.0.0
- **Work package:** A3.1 — Enterprise KPI governance foundation
- **Status:** IMPLEMENTED AS A3.1 GOVERNANCE BASELINE / OWNER-REVIEW REQUIRED
- **Repository:** `akefadaka-git/arna-api`
- **Implementation branch:** `feat/arna-ecc-a3-p0`
- **Base API main SHA:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **A2 evidence snapshot:** API main `6e274c25ecc55b84e026e879d735b77f0e51592f`; App main `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Architecture authority:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0`, frozen 30-domain catalogue, archived in API PR #212 (still a separate, unmerged documentation PR at the time of this register).
- **A3 authority:** `ARNA-ECC-A3-PLAN-001 v1.0.0`, P0-only Owner authorization `ARNA-ECC-A3-AUTH-001`.
- **Change class:** Governance/documentation only. No application runtime, migration, schema, seed, permission, provider or production-data changes.

## 1. Purpose and decision boundary

This register establishes one readiness and ownership record for each of the 30 frozen business domains. It is not a declaration that every domain has a production KPI, a live data provider, a named domain officer, or a complete data contract.

A domain's **readiness status** and a metric's **approval status** are separate:
- A domain may be assessed while having no approved KPI.
- A source/provider key is not, by itself, an approved KPI.
- A KPI is not approved until its definition, formula, source, aggregation, accountable owner, steward, scope, period, unit/currency, freshness, validation and approval evidence are recorded.
- A missing named owner or steward is recorded as a governance gap; no person or role-holder is inferred.
- Priority (P1/P2/P3) is sequencing guidance from the A3 plan, not evidence of source readiness.

## 2. Readiness classification

| Status | Meaning |
|---|---|
| **READY** | Authoritative source and provider contract are evidenced; metric definition/formula, aggregation, accountable owner and data steward are assigned; scope, freshness and validation are specified and approved. |
| **PARTIAL** | Some source/provider evidence exists, but one or more required definition, contract, ownership, stewardship, scope, freshness or approval elements remain unverified. |
| **UNAVAILABLE** | No currently supportable KPI source/provider contract is evidenced in the A2 baseline for this domain. This does not prove that no operational data exists anywhere in ARNA. |

Metric approval states used in this register:
- **NOT APPROVED** — no production KPI definition is authorized.
- **CANDIDATE SOURCE ONLY** — an existing provider/source key is recorded for investigation; it is not a metric definition or approval.
- **NOT ASSESSED** — no candidate KPI has passed the required evidence gate.

## 3. Evidence baseline and confirmed provider inventory

The A2 read-only reconciliation identified only these API provider keys:
- `finance.trial_balance` — associated with D11 Finance & Accounting.
- `people.workforce.active` — associated with D09 People & Human Capital.

The A2 reconciliation did not establish an approved KPI formula, aggregation policy, accountable named domain owner, named data steward, complete provider contract, or production approval for either key. Therefore both domains are **PARTIAL**, not READY.

No additional domain provider key is promoted by inference from a model, route, service, migration, module, UI screen or legacy PR. For the other 28 domains, no currently supportable KPI/provider contract was evidenced in the A2 baseline; they are **UNAVAILABLE** for KPI readiness purposes until evidence is supplied and reconciled.

## 4. Thirty-domain readiness and ownership register

**Ownership convention:** “Accountable domain” identifies the domain that owns the authoritative business capability under the frozen A1 catalogue. The named accountable officer and named data steward are explicitly **NOT RECORDED / NOT ASSIGNED** unless verified evidence is added through a controlled revision. These are required assignments, not invented names.

| ID | Frozen A1 domain | Accountable domain / ownership boundary | A3 priority | Readiness | Evidence-backed source / KPI position | Named accountable officer | Data steward | KPI approval |
|---|---|---|---:|---|---|---|---|---|
| D01 | Corporate Strategy & Enterprise Planning | D01 owns strategy, strategic themes, enterprise plans and priorities; excludes source KPI calculation and project execution. | P1 | UNAVAILABLE | No D01 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D02 | Enterprise Performance Management | D02 owns objectives, KPI definitions, targets, scorecards, review cycles and performance accountability; does not own source transactions. | P1 | UNAVAILABLE | No D02 provider contract evidenced in A2. D02's KPI-governance role does not itself establish source data. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D03 | Corporate Governance & Board Management | D03 owns governance bodies, board/committee mandates, appointments, delegations, meetings, decisions and resolutions. | P1 | UNAVAILABLE | No D03 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D04 | Enterprise Risk Management | D04 owns enterprise risk identification, assessment, appetite, treatment, monitoring and escalation. | P2 | UNAVAILABLE | No D04 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D05 | Compliance & Internal Control Management | D05 owns compliance obligations, control frameworks, assessments, attestations, findings and remediation evidence. | P2 | UNAVAILABLE | No D05 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D06 | Legal & Regulatory Affairs | D06 owns legal matters, regulatory monitoring/interpretation, legal review, disputes, claims and statutory filings. | P2 | UNAVAILABLE | No D06 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D07 | Project, Programme & Portfolio Management | D07 owns project/programme delivery lifecycle, milestones, dependencies, delivery budgets, change control and benefits realization; not enterprise capital allocation. | P2 | UNAVAILABLE | No D07 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D08 | Organization & Structure Management | D08 owns organizational units, hierarchy, structural taxonomy and effective-dated assignments; structure does not grant application authorization. | P3 | UNAVAILABLE | No D08 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D09 | People & Human Capital Management | D09 owns canonical person/workforce lifecycle records, employment/engagement, positions, workforce planning, skills and personnel administration. | P1 | PARTIAL | Existing key `people.workforce.active` is evidenced. Formula, inclusion/exclusion rules, period, tenant/entity scope, freshness, named owner and steward are not established as an approved KPI contract. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED — candidate source only |
| D10 | Shareholder & Ownership Management | D10 owns statutory shareholder register, share classes/holdings, transfers, certificates and ownership entitlements; not GL or wallet ledger. | P2 | UNAVAILABLE | No D10 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D11 | Finance & Accounting Management | D11 owns GL, chart of accounts, journals, accounting periods, legal-entity books, trial balances, statements and consolidation. | P1 | PARTIAL | Existing key `finance.trial_balance` is evidenced. Metric formula/aggregation, accounting basis, period, legal-entity scope, currency treatment, consolidation policy, freshness, named owner and steward are not established as an approved KPI contract. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED — candidate source only |
| D12 | Treasury & Cash Management | D12 owns liquidity planning, cash position, bank operations, forecasts, transfers, reconciliation and liquidity execution; accounting recognition remains D11. | P1 | UNAVAILABLE | No D12 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D13 | Loan & Credit Management | D13 owns credit policy application, loan applications/contracts, disbursement, repayment, accruals, delinquency and loan subledger. | P2 | UNAVAILABLE | No D13 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D14 | Wallet & Digital Financial Services | D14 owns wallet balances/ledger, obligations, payment records, allocations, reversals and digital-finance transaction lifecycle. | P2 | UNAVAILABLE | No D14 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D15 | Procurement & Supplier Management | D15 owns supplier onboarding, sourcing, requisitions, tenders, evaluations, purchase approvals/orders and procurement lifecycle. | P3 | UNAVAILABLE | No D15 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D16 | Inventory & Supply Chain Management | D16 owns stock items, warehouses, receipts, issues, transfers, counts, replenishment and inventory valuation inputs. | P3 | UNAVAILABLE | No D16 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D17 | Sales, Marketing & Customer Relationship Management | D17 owns market/customer records, leads, opportunities, campaigns, quotations, sales orders and customer relationship lifecycle. | P3 | UNAVAILABLE | No D17 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D18 | Operations & Service Delivery Management | D18 owns operational work execution, service requests/orders, work allocation, fulfilment, incidents and service completion evidence. | P2 | UNAVAILABLE | No D18 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D19 | Enterprise Asset & Facilities Management | D19 owns operational asset/facility registers, custody, maintenance, inspections, condition and disposal; not investment holdings. | P3 | UNAVAILABLE | No D19 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D20 | Documents & Records Management | D20 owns formal record identity, classification, registration, approval, issuance, retention, archive, legal hold and revocation; file storage is not records authority. | P2 | UNAVAILABLE | No D20 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D21 | Communication & Stakeholder Engagement | D21 owns stakeholder communication plans, announcements, correspondence, publication approvals, engagement and feedback. | P3 | UNAVAILABLE | No D21 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D22 | Calendar, Events & Scheduling Management | D22 owns calendars, events, invitations, attendance, recurrence, reservations, scheduling and reminders; linked-resource authorization stays with its owner. | P3 | UNAVAILABLE | No D22 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D23 | Enterprise Reporting & Business Intelligence | D23 owns governed analytical datasets, semantic definitions for cross-domain reporting, lineage, models, dashboards and reports; D02 owns KPI definitions/targets. | P2 | UNAVAILABLE | No D23 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D24 | Information Technology & Digital Systems Management | D24 owns IT service/system lifecycle, application portfolio, service operations, technology change, support and availability. | P3 | UNAVAILABLE | No D24 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D25 | Investment & Capital Management | D25 owns investment appraisal, capital proposals/decisions, allocation, approval gates, commitments and investment expectations; D07 owns delivery. | P2 | UNAVAILABLE | No D25 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D26 | Business Development & Strategic Partnerships | D26 owns opportunity development, partner evaluation, relationship governance, collaboration agreements and partnership lifecycle. | P2 | UNAVAILABLE | No D26 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D27 | Corporate Development, Mergers & Acquisitions | D27 owns mergers, acquisitions, divestitures, restructuring, transaction pipeline, due-diligence coordination and integration/separation planning. | P3 | UNAVAILABLE | No D27 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D28 | Product, Service & Innovation Management | D28 owns product/service concepts, discovery, requirements, design, innovation pipeline, roadmap and release decisions. | P3 | UNAVAILABLE | No D28 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D29 | Grants, Funding & Financing Management | D29 owns grant/funding opportunities, applications, funding agreements, donor/lender conditions, restricted-fund obligations and funding lifecycle. | P2 | UNAVAILABLE | No D29 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |
| D30 | Enterprise Investment Asset Portfolio | D30 owns investment asset/holding register, valuation inputs, performance, risk/return monitoring and exit/disposal tracking after acquisition/commitment. | P2 | UNAVAILABLE | No D30 provider contract evidenced in A2. | NOT RECORDED | NOT ASSIGNED | NOT APPROVED |

## 5. KPI definition and approval record

No KPI is approved by this register. The following two source references are candidates for further definition only:

| Candidate source | Domain | Existing evidence | Missing approval evidence |
|---|---|---|---|
| `people.workforce.active` | D09 | Provider key found in A2 API baseline. | Business definition; included/excluded worker categories; formula; as-of/period semantics; tenant/entity scope; source lineage; freshness/SLA; validation; accountable officer; steward; explicit KPI approval. |
| `finance.trial_balance` | D11 | Provider key found in A2 API baseline. | Metric/measure definition; debit/credit and balance semantics; accounting period; legal-entity scope; currency; chart-of-accounts mapping; consolidation policy (if any); freshness; reconciliation controls; accountable officer; steward; explicit KPI approval. |

No consolidated Finance KPI is approved. Legal-entity books must remain separately scoped unless a formally approved consolidation policy and corresponding source evidence are established. No currency conversion or ETB aggregation is assumed.

## 6. Required fields before any KPI can move to APPROVED

Every proposed KPI must have a unique stable identifier and a complete record containing:

1. KPI ID, display name and version.
2. Business definition, intended decision/use and exclusions.
3. Accountable business domain and named accountable officer.
4. Named data steward and stewardship responsibility.
5. Authoritative source domain, source key/reference and lineage.
6. Formula, numerator/denominator where relevant, aggregation and calculation version.
7. Grain, dimensions, filters and deduplication rules.
8. Tenant scope and legal-entity scope; explicit cross-entity rules.
9. Unit and currency; conversion policy where applicable.
10. Reporting period, as-of semantics, timezone/calendar and cut-off.
11. Freshness target, observed/generated timestamps and stale-data behavior.
12. Validation, reconciliation, quality thresholds and negative tests.
13. Permission requirements, including separate widget/data authorization.
14. Known limitations, dependencies and failure behavior.
15. Review evidence, approval authority, approval date and effective date.

A missing mandatory field blocks approval. Unknown, stale, partial, unauthorized or failed data must not be rendered as a complete enterprise KPI.

## 7. Authorization, tenant and domain-boundary controls

- Preserve canonical RBAC tables: `roles`, `permissions`, `user_has_roles`, `user_has_permissions`, `role_has_permissions`.
- Keep dashboard audience authorization separate from each widget's data permission.
- Keep `people.workforce.view` independently gated; dashboard access must not imply workforce-data access.
- No wildcard grants, inferred access, or permission expansion is part of A3.1.
- Enforce tenant and legal-entity scope at the source/provider boundary, not merely in presentation filters.
- No cross-entity Finance aggregation or consolidated reporting without approved accounting/consolidation policy.
- D02 owns KPI definitions and targets; source domains own operational records; D23 owns governed analytical datasets and reporting products.
- Cross-domain consumers use approved references/events/read models and may not create competing authoritative records.
- Amharic-first labels and Ethiopian Calendar support remain localization requirements for future presentation; they do not alter source-period semantics without an explicit metric definition.

## 8. Ownership assignment and remediation queue

| Work item | Required action | Exit evidence | Status |
|---|---|---|---|
| OWN-01 | Appoint a named accountable owner for each domain D01–D30 through the appropriate Owner/governance process. | Approved 30-row officer assignment with effective dates and authority references. | MISSING |
| OWN-02 | Appoint a named data steward for each domain/provider scope. | Approved stewardship assignments and data responsibilities. | MISSING |
| KPI-09 | Reconcile `people.workforce.active` definition, population rules, scope, formula, freshness and validation with D09 owner/steward. | Complete KPI record and source-backed tests; explicit approval. | PARTIAL |
| KPI-11 | Reconcile `finance.trial_balance` accounting semantics, legal-entity/currency scope, period, reconciliation and consolidation exclusions with D11 owner/steward. | Complete KPI record and source-backed tests; explicit approval. | PARTIAL |
| SRC-01 | Identify authoritative source/provider evidence for D01–D08 and D10, D12–D30 without inferring readiness from code presence. | Source contract and lineage evidence per domain, or documented unavailable reason. | MISSING |
| GOV-01 | Establish review and change-control workflow for KPI definitions, formula versions, owner/steward changes and retirement. | Approved workflow, version history and decision log. | MISSING |

## 9. A3.1 acceptance assessment

| Acceptance criterion | Result | Evidence / limitation |
|---|---|---|
| All 30 frozen domains represented exactly once | PASS | D01–D30 enumerated against A1 v1.2.0. |
| Accountable domain boundary identified for every row | PASS | Domain ownership boundary recorded from frozen A1 scope. |
| Named accountable officers assigned | MISSING | No verified named officer assignments in A2 evidence; explicitly left unassigned. |
| Named data stewards assigned | MISSING | No verified stewardship assignments in A2 evidence; explicitly left unassigned. |
| Readiness classification based on source evidence | PASS | D09 and D11 PARTIAL; remaining 28 UNAVAILABLE against A2 provider evidence. |
| Approved KPI definitions established | MISSING | Neither existing provider key has a complete approved KPI definition. |
| No invented formulas, owners, metrics or provider readiness | PASS | Unknown fields remain explicit; no KPI promoted to approved. |
| Tenant/legal-entity and RBAC guardrails preserved | PASS — REGISTER SCOPE | Register records controls; runtime enforcement remains outside A3.1 documentation-only change. |
| Versioned ownership/readiness record | PASS | This controlled v1.0.0 register. |
| A3.1 fully closed | PARTIAL | Register established; named owners/stewards and KPI approval evidence remain outstanding. |

## 10. Change control and handover

- This record is a versioned A3.1 governance baseline; update only through a controlled successor/addendum.
- Reconcile status against exact repository/source evidence before changing any row.
- A domain moves to READY only when every readiness criterion is evidenced and approved.
- A KPI becomes APPROVED only through its own complete metric record and approval decision.
- Changes to provider implementations, database schema, migrations, permissions, dashboards, or production configuration are outside this register's scope.
- A3.2 provider contract work and A3.3 bounded authorization/security work remain separate P0 work packages.
- P1/P2 work packages A3.4–A3.8 remain unauthorized; merge, A4 and A5 remain separate gates.

**Register disposition:** A3.1 register artifact created; governance coverage complete across 30 domains; KPI readiness is 2 PARTIAL / 28 UNAVAILABLE / 0 READY; approved KPI count is 0; named accountable officer assignments are 0/30 verified; named data steward assignments are 0/30 verified. A3.1 is **PARTIAL, NOT VERIFIED-CLOSED** pending owner/steward assignments and source-backed KPI approvals.
