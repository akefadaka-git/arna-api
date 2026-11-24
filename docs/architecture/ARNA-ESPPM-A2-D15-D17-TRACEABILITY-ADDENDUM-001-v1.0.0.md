# ARNA ESPPM A2 D15/D17 Source-to-Requirement Traceability Addendum 001

**Version:** 1.0.0  
**Status:** A2 read-only reconciliation addendum  
**Date:** 2026-11-24  
**Parent workstream:** ARNA-ESPPM-A2-CLOSURE-REPORT-001  
**Related baseline:** ARNA-ESPPM-D15-D17-A2-CONTRACT-BASELINE-001 v1.0.0  
**Disposition authority:** Approved A2 domain dispositions; this addendum does not amend them.

## 1. Purpose and control

This addendum records the source-to-requirement reconciliation of D15 Procurement and D17 Sales against the exact destination repository `main` snapshots inspected on 2026-11-24. It distinguishes implemented API/UI evidence from documented targets, adjacent-domain foundations, missing contracts, unresolved authority and future A3 prerequisites.

This is documentation-only A2 work. It does not create or approve an A1 domain contract, authorize implementation, change domain dispositions, approve residual exceptions, close A2, authorize PR merge, or authorize A4/A5.

## 2. Immutable inspection baseline

| Repository | Ref inspected | Result |
|---|---|---|
| `akefadaka-git/arna-api` | `main` = `6e274c25ecc55b84e026e879d735b77f0e51592f` | Recursive tree complete; `truncated=false` |
| `akefadaka-git/arna-app` | `main` = `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Recursive tree complete; `truncated=false` |

The source inspection used exact commit refs, not PR #219's documentation branch. No `main` ref was changed.

## 3. Source evidence register

| Evidence | Exact source | Blob SHA | What it establishes |
|---|---|---|---|
| Operations and Finance UI implementation contract | App `docs/ARNA-OPERATIONS-FINANCE-UI-IMPLEMENTATION-CONTRACT.md` | `768dfc0c366d1d335bf8e1eaa8aef5b4009d2def` | Target Procurement and Sales route families and sequencing; explicitly requires backend contracts before pages are implemented. Planning contract, not implemented features. |
| Admin route tree | App `src/router/routes-admin.ts` | `5b3b648a31dad0ba19967387fa8d503bb4996572` | Registered admin routes are public-content, corporate-site and audit-security plus dashboard/redirects; no D15/D17 route implementation is established. |
| Admin dashboard | App `src/router/pages/AdminDashboardRoute.vue` | `dab6c306bc7fa059a6549fabf257c43fad849c6a` | Static welcome surface; no fetch, state or metrics. Not a functional D15/D17 dashboard. |
| Corporate-site admin API client | App `src/features/public-site/admin-api.ts` | `619979603bdd4f234174ff37be71a65b5fe0c7fe` | Corporate content lifecycle and public inquiry intake. Public inquiries are not a Sales customer/opportunity/order lifecycle. |
| API route registry | API `routes/api.php` | `7284faefc156548394a9ca7edfc2ead791773fb5` | Inspected route registry contains no Procurement/Sales route family; the search match was a Finance dashboard/widget comment, not a D15/D17 route. |
| Finance journal source classification | API `mases_core/src/Domains/Finance/Enums/JournalSourceType.php` | `e0991fc4026952589102691abc2b3a1393b21b20` | Includes `PROCUREMENT` source provenance classification; enum documentation expressly says it is not authorization and source domains own their business records. It does not implement Procurement. |
| Admin audit/security controller | API `app/Http/Controllers/Admin/AdminAuditSecurityController.php` | `6b371710facbcfe375bff87340041bb24263e678` | Tenant-scoped audit/security investigation and audited security-event resolution; shared control capability, not D15/D17 workflow implementation. |

## 4. D15 Procurement traceability

| Requirement area | Existing evidence | Planned/unimplemented capability | Assessment |
|---|---|---|---|
| Requisitions and approval lifecycle | No dedicated D15 API domain root or route contract identified in inspected API tree | UI contract lists requisition list/create/detail and workflow | Missing implementation |
| Sourcing, RFQs, quotations and evaluations | No D15 API contract identified | UI contract lists sourcing, RFQs, quotations and evaluations | Planned only |
| Award, purchase orders and supplier contracts | Finance `JournalSourceType::PROCUREMENT` provides source classification only | UI contract lists awards, orders and contracts | Missing implementation |
| Receipts, supplier invoices and closeout | Adjacent Finance foundations exist; no Procurement-owned receipt/invoice contract or source adapter established | UI contract lists receipts, invoices and closeout | Missing implementation |
| Supplier lifecycle and due diligence | No dedicated supplier domain/API contract identified | Supplier lifecycle must be specified within D15 contract | Missing implementation |
| Authority, tender controls and thresholds | Draft PSC policy/control materials are not adopted legal/business authority | Requires source-backed policy, thresholds, segregation and approval matrix | Blocked pending approved authority |

**D15 finding:** The App UI contract describes the intended requisition-to-payment interface sequence, but the backend domain/API contract and corresponding App consumers are not established in the inspected `main` snapshots. The Finance procurement source enum is not evidence of a functioning procurement workflow. D15 remains **MISSING**.

## 5. D17 Sales traceability

| Requirement area | Existing evidence | Planned/unimplemented capability | Assessment |
|---|---|---|---|
| Customer/commercial account lifecycle | Public-site inquiry intake exists; it is not a Sales CRM lifecycle | UI contract lists customer routes | Missing implementation |
| Quotations, orders and commercial contracts | No dedicated D17 API domain/routes identified | UI contract lists quotations, orders and contracts | Planned only |
| Pricing, discounts, credit and approvals | No adopted Sales authority contract or approved limits identified | Requires approved price/discount/credit/exception authority | Missing; authority unresolved |
| Fulfilment and delivery | No D17 source contract established; D18/D16 are separate owning domains | UI contract lists fulfilment and delivery routes | Planned only |
| Invoices, returns and receivables | Finance accounting foundations exist, but no D17 source-event adapter or Sales lifecycle contract is established | UI contract lists invoices, returns and receivables | Missing implementation |
| Customer communication and evidence | Shared D20/D21 capabilities are adjacent foundations | D17-specific records, evidence references and communication triggers remain to be contracted | Partial shared foundation only |

**D17 finding:** Public corporate inquiry handling does not establish customer account, quotation, order, pricing, credit, fulfilment or return management. Finance receivables capability does not itself establish the Sales source domain. D17 remains **MISSING**.

## 6. Cross-domain ownership and integration boundaries

| Capability | Owning domain | Required boundary/integration |
|---|---|---|
| Requisition, sourcing, supplier selection, award, purchase order and supplier contract | D15 | D03 governance/authority; D05 controls; D06 legal; D20 evidence |
| Goods receipt and stock movement | D16 | D15 purchase order; D20 evidence; D11 accounting |
| Service receipt and acceptance | D18 | D15 contract/order; D20 evidence; D11 accounting |
| Supplier invoice recognition and payables accounting | D11 | D15 source event; authoritative Finance adapter |
| Supplier payment execution and bank reconciliation | D12 | D11 approved payable; verified bank mandate/payment authority |
| Customer, quotation, order, contract, pricing and returns | D17 | D03 authority; D06 legal; D20 evidence; D21 communication |
| Product/service catalogue and lifecycle | D28 | D17 commercial offering; D18 delivery |
| Order fulfilment and service delivery | D18 | D17 accepted order; D16 stock where applicable |
| Customer invoice and receivable accounting | D11 | D17 source event; authoritative Finance adapter |
| Wallet payment and allocation | D14 | D17 customer/order reference; D11 accounting adapter |
| Bank settlement and cash reconciliation | D12 | Settlement instruction and bank evidence |
| Records, evidence and provenance | D20 | Source-domain record references and immutable lifecycle evidence |

Finance owns accounting records and controls; it must not become the owner of Procurement or Sales business decisions. D12 owns bank execution and reconciliation. Wallet allocation is not bank settlement. A payment record alone does not prove bank settlement.

## 7. Shared authority and security constraints

1. Tenant membership and RBAC are necessary access controls but do not independently establish delegated legal/business authority.
2. No arbitrary monetary thresholds, tender limits, award limits, prices, discounts, customer credit limits or refund limits are approved by this addendum.
3. Every consequential transition must resolve current, source-backed authority and policy; missing, expired, conflicting or out-of-scope authority must fail closed.
4. Maker/checker segregation, conflict-of-interest controls, reasoned exceptions, immutable audit history, concurrency protection and tenant/legal-entity scope must be specified before implementation.
5. API authorization is authoritative. App route guards and UI permission metadata are navigation/usability controls only.

## 8. A3 entry prerequisites

D15 and D17 must not enter A3 until all applicable conditions are separately satisfied:

- Owner-approved A1 domain contract and versioned lifecycle/state-transition specification.
- Approved policy, competent authority interfaces and source-backed authority catalogue; no invented thresholds.
- Versioned API contract, permission catalogue, validation/error contract and tenant/legal-entity isolation rules.
- Explicit source/consumer event contracts and idempotent Finance adapters.
- Agreed D11 accounting, D12 Treasury, D16 Inventory, D18 Operations and D20 evidence boundaries.
- Defined vertical slice, threat/control analysis, migration/data plan and test plan.
- App route/feature/API-consumer plan mapped to confirmed backend contracts.
- Contract, authorization, tenant isolation, concurrency, audit, accounting and end-to-end acceptance criteria.
- Separate explicit Owner A3 implementation authorization.

These are entry prerequisites, not implementation instructions or implied authorization.

## 9. Future A4 verification scenarios (NOT RUN)

The following are proposed acceptance scenarios for a separately authorized implementation and later A4 stage:

- D15 requisition creation, approval segregation, sourcing/evaluation, award and purchase-order transition against approved authority.
- Supplier due diligence, conflict checks, contract evidence and immutable version history.
- Partial/full goods receipt, rejected receipt, service acceptance and three-way matching where applicable.
- Supplier invoice recognition creates one balanced, idempotent Finance event; retries do not duplicate journals.
- Payment initiation requires D12 mandate and authority; bank settlement/reconciliation is independently evidenced.
- D17 customer lifecycle, quotation versioning, price/discount approval and credit checks against approved authority.
- Order acceptance, fulfilment, partial delivery, returns and cancellation with controlled transitions.
- Invoice/receivable source event, Wallet payment attribution and independent Treasury settlement reconciliation.
- Tenant and legal-entity isolation, maker/checker separation, stale-version rejection, audit completeness and evidence access controls.
- App route/API contract alignment, real API integration, mobile/accessibility states and error recovery.

No tests or CI were run as part of this A2 addendum.

## 10. Deferred post-deployment source-record intake

Corporate and financial primary source records (including adopted policies, delegations, bank mandates, account ownership records, statements, reconciliations, supplier/customer contracts and transaction vouchers) are **not requested before ARNA is deployed and running on the server**.

Pre-deployment work may specify secure intake, provenance, authenticity/currentness checks, reviewer segregation, access controls and evidence-retention requirements. Actual records are to be uploaded and verified only through the approved post-deployment process. Until then, authority-dependent capabilities remain unverified or blocked; this timing rule does not justify upgrading D15 or D17.

## 11. A2 disposition and change-control decision

| Domain | Approved A2 disposition | Effect of this addendum |
|---|---|---|
| D15 Procurement | **MISSING** | Unchanged |
| D17 Sales | **MISSING** | Unchanged |

- D01 remains **UNVERIFIED**.
- D12 remains **MISSING**.
- All other approved A2 dispositions remain unchanged.
- This addendum is not A2 closure approval and does not accept residual exceptions.
- A2 remains **PARTIAL — NOT VERIFIED-CLOSED**; R07 remains for Owner decision.
- No PR merge, A3 implementation, A4 verification or A5 deployment is authorized.
- No application source, schema, migration, seeder, runtime configuration or production data was changed.
- No tests/CI were run.

**Control outcome:** Record the traceability evidence on the existing PR #219 documentation branch only. Preserve both repository `main` refs and all approved domain dispositions.
