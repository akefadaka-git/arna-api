# ARNA Enterprise Strategy, Planning & Performance Management (ESPPM)
## A1 Architecture Specification — Frozen 30-Domain Catalogue

- **Document ID:** ARNA-ESPPM-A1-SPEC-002
- **Version:** 1.2.0
- **Status:** OWNER-APPROVED / FROZEN A1 BASELINE
- **Approval:** Explicit Owner approval recorded 2026-11-24
- **Repository:** `akefadaka-git/arna-api`
- **Archival branch base:** API `main` at `40428db0a41d54397dd2aee482c9395f7950e9d0`
- **Change class:** Architecture documentation only
- **Authority boundary:** A1 specification and freeze only. This record does not authorize A2 closure, A3 implementation, A4 verification, merge to `main`, or deployment.

## 1. Document control and historical lineage

This document records the Owner-approved reconstructed successor specification for the ARNA ESPPM business-domain architecture.

- **A1 v1.0** remains preserved as the historical original 24-domain baseline.
- **A1 v1.1 / ARNA-ESPPM-A1-ADD-001** remains preserved as the historical Owner-approved amendment that expanded D07 and introduced D25–D30.
- **A1 v1.2.0 / ARNA-ESPPM-A1-SPEC-002** is the current authoritative, frozen 30-domain successor.

**Historical-source qualification:** The v1.2.0 successor was reconstructed from the available repository evidence and prior approved architecture discussion because the complete original D01–D24 catalogue and the complete v1.1 addendum wording could not be recovered verbatim. This repository record does not claim to be a verbatim restoration of those historical texts. It preserves the approved 30-domain catalogue and the approved ownership/boundary decisions as the current baseline.

The frozen A1 baseline must not be silently rewritten. A material change requires a controlled, versioned amendment or successor and explicit Owner approval. Preserve v1.0 and v1.1 unchanged.

## 2. Purpose and architecture principle

ESPPM defines the enterprise business capability map and the boundaries through which ARNA's business domains own authoritative records, decisions, workflows, controls and measures. The catalogue is a business-domain architecture, not a list of screens, Laravel modules, database schemas, teams or deployment units.

Each domain must have one clearly identified accountable owner for its authoritative business records and lifecycle. Other domains may consume approved references, events or read models, but must not create competing sources of truth or bypass the owning domain's authorization and controls.

## 3. Frozen 30-domain catalogue

| ID | Domain | Scope and authoritative responsibility |
|---|---|---|
| D01 | Corporate Strategy & Enterprise Planning | Defines enterprise mission, strategic direction, strategic themes, multi-year plans, enterprise priorities and the approved cascade from strategy into business plans. It owns the strategy/planning baseline, not operational KPI calculation or project execution. |
| D02 | Enterprise Performance Management | Owns enterprise objectives, KPI definitions, targets, scorecards, performance periods, review cycles, variance commentary, corrective actions and performance accountability. It consumes governed data and does not become the system of record for source transactions. |
| D03 | Corporate Governance & Board Management | Owns governance bodies, board and committee structures, mandates, positions, terms, appointments, memberships, seats, occupancies, acting appointments, delegations, authority records, meetings, decisions and resolutions, subject to approved legal and governance rules. |
| D04 | Enterprise Risk Management | Owns enterprise risk identification, assessment, classification, risk registers, appetite/tolerance, treatment plans, control-risk linkage, risk ownership, monitoring and escalation. It does not replace compliance testing, internal audit or legal advice. |
| D05 | Compliance & Internal Control Management | Owns compliance obligations registers, control frameworks, control design/operation assessments, compliance monitoring, attestations, findings, remediation tracking and assurance evidence. It distinguishes compliance/control assurance from enterprise risk ownership and legal interpretation. |
| D06 | Legal & Regulatory Affairs | Owns legal matters, regulatory interpretation and monitoring, legal opinions, contracts/legal review, disputes, claims, statutory filings and legal obligations. It does not own the operational execution of procurement, finance or governance workflows merely because they have legal implications. |
| D07 | Project, Programme & Portfolio Management | Owns project/programme/portfolio initiation, business cases for delivery, scope, schedules, milestones, dependencies, resources, delivery budgets, risks/issues, change control, benefits realization and stage-gate delivery governance. It coordinates delivery and does not own enterprise investment allocation or the resulting investment holdings. |
| D08 | Organization & Structure Management | Owns legal-entity organizational structures where delegated, organizational units, hierarchy, structural taxonomy, effective-dated assignments and organizational lifecycle. Unit membership or assignment does not itself grant application authorization, employment status, ownership or governance authority. |
| D09 | People & Human Capital Management | Owns the canonical natural-person profile and workforce lifecycle records, including employment/engagement, positions, workforce planning, skills, development, attendance/leave and personnel administration as approved. Authentication accounts, tenant access, share ownership and governance authority remain distinct relationships and are not implied by a person profile. |
| D10 | Shareholder & Ownership Management | Owns the statutory shareholder register, shareholder identity type, share classes, holdings, share movements/transfers, certificates, ownership entitlements, shareholder lifecycle and shareholder-facing register evidence. It does not own the general ledger or wallet/payment ledger. |
| D11 | Finance & Accounting Management | Owns the general ledger, chart of accounts, journal lifecycle and posting, accounting periods, legal-entity books, trial balances, financial statements, accounting policies and consolidation. It consumes approved source-domain events and does not mutate Loan, Wallet, Shareholder or Procurement source records. |
| D12 | Treasury & Cash Management | Owns liquidity planning, cash positioning, bank accounts and bank operations, cash forecasts, cash transfers, treasury controls, bank reconciliation and liquidity risk execution. Accounting recognition and reporting remain with Finance; wallet obligations and payment records remain with Wallet. |
| D13 | Loan & Credit Management | Owns credit products and policy application, loan applications, assessment, approval workflow, loan contracts, disbursement lifecycle, repayment schedules, installments, allocations, accruals, restructurings, delinquency and write-off workflow. It owns the loan subledger and supplies controlled accounting events to Finance. |
| D14 | Wallet & Digital Financial Services | Owns customer/member wallets, wallet balances as defined by its ledger, obligations, payment records, payment allocations, wallet entries, reversals and digital-finance transaction lifecycle. It integrates with external banks/payment rails and Finance without surrendering its source-of-truth responsibility. |
| D15 | Procurement & Supplier Management | Owns supplier onboarding and records, sourcing, requisitions, tenders/quotations, evaluation, purchase approvals, purchase orders, supplier contracts and procurement lifecycle. Receipt/inventory and accounting recognition are handed to their owning domains through controlled events. |
| D16 | Inventory & Supply Chain Management | Owns stock items, warehouses/locations, receipts, issues, transfers, reservations, stock counts, replenishment, supply movements and inventory valuation inputs. It does not own supplier contracting or the general ledger. |
| D17 | Sales, Marketing & Customer Relationship Management | Owns market/customer records, leads, opportunities, campaigns, quotations, sales orders and customer relationship lifecycle. It hands fulfilment to Operations and accounting recognition to Finance; it does not duplicate product master authority. |
| D18 | Operations & Service Delivery Management | Owns operational work execution, service requests/orders, work allocation, service-level performance, fulfilment, delivery, operational incidents and service completion evidence. It consumes approved products, customer commitments and resource data from their owning domains. |
| D19 | Enterprise Asset & Facilities Management | Owns operational asset and facilities registers, locations, custody, maintenance, inspections, utilization, condition and disposal workflows for assets used to operate the enterprise. It does not own investment appraisal or investment-holding portfolio performance. |
| D20 | Documents & Records Management | Owns formal document identity, classification/taxonomy, numbering, registration, review, approval, issuance, publication, verification, retention, archival, legal hold and revocation. Shared file storage is binary persistence and version transport, not formal records authority. |
| D21 | Communication & Stakeholder Engagement | Owns stakeholder communication plans, announcements, correspondence workflows, message approval/publication, engagement records, stakeholder feedback and communication accountability. Shared notification infrastructure delivers messages but does not own the business communication decision. |
| D22 | Calendar, Events & Scheduling Management | Owns enterprise calendars, events, invitations, attendance/response, recurrence, reservations, scheduling and reminder lifecycle. Linked business resources remain authorized by their owning domains; a calendar link does not grant access to the linked resource. |
| D23 | Enterprise Reporting & Business Intelligence | Owns governed analytical datasets, semantic definitions for cross-domain reporting, data lineage, analytical models, dashboards, reports and decision-support outputs. KPI definitions and target ownership remain with D02; source transactions remain with their business domains. |
| D24 | Information Technology & Digital Systems Management | Owns enterprise IT service management and digital-system lifecycle where applicable: system/service inventory, application portfolio, service operations, technology change, support, availability and technology governance. Shared ARNA platform primitives are cross-cutting infrastructure, not separate business-domain ownership by default. |
| D25 | Investment & Capital Management | Owns investment opportunity appraisal, capital proposals, investment decisions, capital allocation, investment approval gates, capital commitments, funding strategy and investment performance expectations. Project delivery remains with D07; accounting remains with D11. |
| D26 | Business Development & Strategic Partnerships | Owns opportunity development, strategic partnership pipeline, partner evaluation, relationship governance, collaboration agreements and partnership lifecycle. Legal contract review, investment approval and operational delivery remain with their respective domains. |
| D27 | Corporate Development, Mergers & Acquisitions | Owns corporate transactions such as mergers, acquisitions, divestitures, restructuring and strategic corporate combinations, including transaction pipeline, due diligence coordination, valuation coordination, approval gates and integration/separation planning. Legal, Finance, Governance and People retain their specialist authorities. |
| D28 | Product, Service & Innovation Management | Owns product/service concepts, portfolio lifecycle, discovery, requirements, design, innovation pipeline, roadmap, release decisions and product/service performance hypotheses. Sales owns customer/commercial lifecycle; Operations owns fulfilment and service execution. |
| D29 | Grants, Funding & Financing Management | Owns grant and funding opportunity identification, applications, funding agreements, donor/lender conditions, restricted-fund obligations, milestones, reporting and funding lifecycle. Treasury owns liquidity/bank execution; Finance owns accounting; D25 owns investment capital allocation. |
| D30 | Enterprise Investment Asset Portfolio | Owns the register and portfolio view of investment assets and holdings after acquisition/commitment, including asset-level records, valuation inputs, performance monitoring, risk/return reporting and exit/disposal tracking. D25 owns investment decision and capital allocation; D19 owns operational assets used to run the enterprise. |

## 4. Mandatory ownership and separation rules

1. **Strategy vs performance vs analytics:** D01 owns strategy and planning; D02 owns objectives, KPI definitions, targets and performance review; D23 owns governed analytical data and reporting products.
2. **Governance vs organization vs people vs authorization:** D03 owns governance authority and appointments; D08 owns organizational structure and assignments; D09 owns people/workforce records. None of these relationships automatically grants system permissions. Platform authorization remains a separate control.
3. **Risk vs compliance vs legal:** D04 owns risk; D05 owns compliance obligations and internal-control assurance; D06 owns legal interpretation and legal matters.
4. **Delivery vs investment:** D07 governs project/programme delivery; D25 governs investment appraisal and capital allocation. A project budget does not itself establish investment approval.
5. **Accounting vs treasury vs subledgers:** D11 owns accounting books and posting; D12 owns cash/bank/liquidity operations; D13 owns the loan subledger; D14 owns wallet and payment records. Source domains publish controlled, idempotent events for accounting.
6. **Procurement vs inventory vs finance:** D15 owns supplier/sourcing/order lifecycle; D16 owns stock and supply movements; D11 owns accounting recognition.
7. **Commercial vs product vs operations:** D17 owns customer and sales lifecycle; D28 owns product/service definition and innovation; D18 owns operational fulfilment and service delivery.
8. **Operational assets vs investment holdings:** D19 owns assets/facilities used in operations; D25 owns investment appraisal and allocation; D30 owns the resulting investment asset/holding portfolio.
9. **Formal records vs file storage:** D20 owns the formal record and its lifecycle. Shared file storage owns binary objects and file-version transport only.
10. **Communication vs notification delivery:** D21 owns communication content, approval and stakeholder workflow. Platform notification services provide delivery infrastructure only.
11. **Calendar vs linked resource authorization:** D22 owns calendar/event lifecycle. A linked resource is always authorized by its owning domain; unknown, missing-policy, cross-tenant or denied references fail closed.
12. **Funding vs cash vs investment vs accounting:** D29 owns funding arrangements and conditions; D12 owns cash execution; D25 owns investment allocation; D11 owns accounting.

## 5. Cross-cutting platform capabilities — not additional business domains

The following capabilities are shared platform services. They support all domains and must not be misrepresented as extra business domains or duplicated as domain-owned sources of truth:

- Identity, authentication, recognized-device security and account lifecycle.
- Tenant resolution, tenant isolation and tenant-membership context.
- Authorization, roles, permissions, delegation and policy enforcement.
- Audit trails, security events and immutable evidence.
- Notification delivery and messaging transport.
- Integration/API/event infrastructure, contracts and idempotent message exchange.
- Data governance, data quality, metadata, lineage and controlled master/reference data.
- Cybersecurity, privacy, secrets and key management.
- Workflow, orchestration and automation primitives.
- Binary file/object storage and file-version transport.
- Localization and accessibility, including Amharic-first experience and Ethiopian Calendar support.
- Backup and disaster-recovery capabilities.
- Observability and shared platform operations.

**Count control:** v1.2.0 records these as 13 grouped cross-cutting platform capability areas. The historical 15-capability crosswalk associated with v1.1 is a preserved historical view and must not be silently substituted for the v1.2.0 grouping.

Domain-specific business rules, records and decisions remain owned by their domain even when shared platform services execute common technical functions.

## 6. Minimum domain contract required before implementation

Each domain must have an explicit, versioned contract that identifies:

1. Purpose, scope, accountable owner and authoritative records.
2. Included capabilities and explicit exclusions.
3. Relationships, dependencies and permitted cross-domain references.
4. State machines, lifecycle transitions and business invariants.
5. Tenant, identity, authorization and segregation-of-duties requirements.
6. Audit, evidence, retention and privacy requirements.
7. Events/APIs, idempotency, consistency and reconciliation behavior.
8. Localization requirements, including Amharic and Ethiopian Calendar where relevant.
9. Reporting/KPI ownership and data lineage.
10. Migration, compatibility, rollback and recovery constraints.
11. Acceptance criteria, negative tests, concurrency tests and evidence requirements.
12. Explicit Owner approval status and implementation/merge/deployment gates.

A code folder, model, route, permission, UI screen, branch or draft PR is not by itself an accepted domain contract.

## 7. Version and change control

- This v1.2.0 document is the frozen A1 baseline.
- Preserve A1 v1.0 and A1 v1.1 as historical records; do not overwrite them.
- Changes to domain names, scope, boundaries or cross-cutting separation require a controlled addendum or successor version with an explicit Owner decision.
- A2 is read-only reconciliation against the frozen baseline. A2 findings may recommend amendments but cannot silently amend A1.
- A3 implementation requires separate explicit authorization for a bounded scope.
- A4 verification and A5 deployment remain separate gates. No A1 approval implies merge or deployment authority.

## 8. Approval and archival note

The Owner explicitly approved `ARNA-ESPPM-A1-SPEC-002 v1.2.0` as the reconstructed successor to A1 v1.0 and the Owner-approved v1.1 amendment, approved the 30-domain catalogue, definitions, ownership boundaries and cross-cutting platform separation, and authorized the A1 v1.2.0 specification to be frozen. The Owner subsequently authorized A2 read-only reconciliation.

This file is a repository archival record of that frozen baseline. Its presence on this documentation branch does not merge it into `main`, close A2, authorize implementation, or authorize deployment.
