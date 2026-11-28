# ARNA Finance — Phase 01 Foundation and Document Integration

**Workstream:** Phase 01 — Financial foundation and CoA  
**Status:** Implementation candidate; exact-SHA verification pending  
**Baseline:** API `d9a80d4d38cab45d712b3b3a9589bf05ee6bd5e5`; App `329eaeaf10774c490d11a57cbf9cbd17a4168e3d`  
**Branch:** `feat/finance-phase01-foundation`

## 1. Purpose and boundary

Phase 01 establishes the entity-level accounting profile, fiscal-calendar configuration boundary, currency policy boundary, canonical CoA governance, and document/source integration contracts. It does not post journals, create opening balances, activate foreign-currency posting, determine tax treatment, or execute production migrations.

The existing Finance ledger remains authoritative for accounting entries. Existing Documents and Files domains remain authoritative for document numbering, workflow, signatures, binary storage, file versions and hashes.

## 2. Entity accounting profile

Each tenant/legal-entity pair has one profile:

- functional currency: ETB;
- reporting currency: ETB;
- transaction currency mode: `base_only`;
- display calendar: Ethiopian Calendar (EC) by default, with Gregorian Calendar (GC) selectable for presentation;
- timezone: `Africa/Addis_Ababa`;
- fiscal-year start month/day in EC: unset until the company's approved fiscal-year policy is recorded;
- tax profile: unconfigured until registration and current tax evidence are reconciled;
- accounting policy: owner review required;
- document numbering policy: reference to the canonical Documents standard.

Gregorian DATE remains the persisted accounting date. Ethiopian dates are translated at the Finance boundary through the platform calendar adapter. Fiscal periods are explicit date ranges; no fiscal year start is inferred from the calendar display preference.

Foreign-currency rate storage and non-posting conversion remain available under existing controls. This profile does not enable non-ETB journal posting.

## 3. CoA and opening-balance governance

The canonical 79-account CoA remains sourced from `chart_of_accounts_v1.php`. The seeder remains intentionally unregistered from automatic database seeding. Phase 01 must not invent opening balances, activate tax defaults as tax determinations, overwrite tenant accounts, or alter posted history.

Before any CoA activation, validate all 79 codes, hierarchy, account type, normal balance, posting eligibility, control-account designation, source-domain ownership and tax-category default. Control accounts require an accountable role, reconciliation cadence, required evidence and approved source mappings. No named person or posting adapter is inferred.

## 4. Document lifecycle and uses

Finance references the canonical Documents registry; it does not create a second document-numbering system or duplicate files.

Every finance-relevant document must preserve:
- tenant and legal-entity scope;
- canonical document ID and immutable issued number;
- document type and subtype;
- source-domain/type/ID;
- file ID, immutable file version and content hash where available;
- lifecycle status and approval/signature evidence;
- creator/issuer and timestamps;
- purpose and primary/supporting classification.

Required document uses include:

| Finance process | Required records/evidence |
|---|---|
| General ledger | journal memo, source invoice/receipt, approval evidence, adjustment and reversal memo |
| Cash and bank | cash receipt, payment voucher, deposit slip, cheque, bank statement, transfer advice, cashier close |
| Sales and receivables | quotation, sales order, invoice, tax invoice, credit/debit note, delivery note, customer receipt |
| Procurement and payables | requisition, RFQ/tender, bid comparison, purchase order, contract, GRN, inspection report, supplier invoice/credit note |
| Inventory | stock receipt/issue/transfer/adjustment/count, damaged/expired goods evidence, batch/serial trace |
| Fixed assets | acquisition invoice, title/registration, assignment, maintenance order, depreciation schedule, impairment and disposal evidence |
| Share capital | subscription, share issue/allocation, transfer, class-rights, certificate, capital resolution, dividend notice and settlement |
| Payroll and expenses | approved payroll register, payslip, statutory deduction schedule, expense claim, reimbursement and advance evidence |
| Tax | registration certificate, tax invoice, withholding certificate, VAT/withholding/income-tax return, tax payment receipt |
| Governance and budget | board minutes, resolution, approved budget, delegated authority, investment/dividend approval |
| Financial reporting | trial balance, financial statements, bank reconciliation, cash close, inventory valuation, asset register, audit and regulatory reports |

Document codes already in the Documents registry are reused: INV/04, PUR/12, EXP/13, INC/14, RCT/15, CON/09, CER/05, RES/06, RPT/08, LEG/03 and MEM/02. Document type codes are immutable; a document code does not itself represent an accounting transaction.

A receipt is not considered financially complete merely because a PDF exists. It must reference a real cash/bank/customer/supplier transaction and its allocation/reconciliation status.

## 5. Finance document-reference API

The API exposes `GET /api/finance/documents` under `finance.document.view`. It is tenant-context scoped, requires a legal entity, supports source-domain/type/ID and purpose filters, and returns only canonical issued-document references linked to that entity. Document metadata is a summary; binary access, file versions and lifecycle actions remain on the existing Files/Documents APIs.

Finance-specific linking is performed through `FinanceDocumentLinkService`, not by accepting arbitrary client-supplied document metadata. The service validates the active tenant/entity, requires an issued non-voided/non-revoked document from the same tenant and legal entity, validates the registered source domain and purpose, and uses a unique identity key to make repeat linking idempotent. Finance managers receive the read permission; cashier and Board roles do not receive broad Finance document visibility by default.

## 6. Domain integration ownership

| Domain | Record owner | Finance responsibility | Activation |
|---|---|---|---|
| Documents / Files | Documents / Files | Reference immutable records and evidence | Reference-only |
| Shareholder | Shareholder | Share capital, premium, dividends and balances | Adapter inactive |
| Board Election / Governance | Governance domains | Consume approved resolutions and fee/expense evidence | Adapter inactive |
| Procurement | Procurement | Commitments, GRN matching and payables | Adapter inactive |
| Inventory | Inventory | Valuation and inventory control-account reconciliation | Adapter inactive |
| Fixed Assets | Assets | Capitalization, depreciation, impairment and disposal | Adapter inactive |
| People / Payroll | People / Payroll | Payroll journals and statutory deductions | Adapter inactive |
| Banking / Wallet | Banking / Wallet | Cash movement and settlement reconciliation | Adapter inactive |
| Sales | Sales | Revenue recognition and receivables | Adapter inactive |
| Tax | Tax/Finance | Tax determination, liabilities and returns | Requires current Ethiopian tax validation |

## 7. Source-event integration contract

Every source event must include event ID/version, tenant, legal entity, source domain/type/ID, effective date, currency, idempotency key and occurrence timestamp. Evidence references include document IDs, file-version IDs, content hashes and approval references.

Finance must resolve tenant context server-side, validate active entity/source mappings, verify document ownership and entity consistency, enforce idempotency, and quarantine unknown, stale, revoked or unmapped events. Source domains cannot directly mutate posted journal entries.

## 8. Security and segregation

- Tenant context is server-resolved; client-supplied tenant IDs are not authoritative.
- Entity and document references must match the same tenant and legal entity.
- Document permissions remain governed by Documents; Finance permissions govern financial visibility and action.
- Payroll and shareholder records require domain-level confidentiality checks in addition to Finance access.
- Read-only reporting and evidence access are separate from posting, payment, approval and document issuance.
- No implicit direct user grants are introduced.
- No AI agent may post, pay, issue shares or alter issued documents.

## 9. Phase 01 acceptance gates

1. Clean MySQL migration creates profile and document-reference structures without changing existing ledger/document rows.
2. Every existing legal entity receives a profile through a deterministic, idempotent backfill only after the migration is explicitly approved for that environment; migration itself must not invent fiscal-year or tax settings.
3. Entity profile is unique by tenant/entity and tenant-isolated.
4. ETB-only posting remains enforced; multi-currency mode cannot be enabled through an ordinary entity create/update request.
5. Fiscal start month/day are either both null or a valid EC month/day; no default fiscal year is invented.
6. Document links enforce tenant/entity scope, reject mismatched document entity, preserve immutable document identity and do not duplicate files.
7. Document type registry and canonical numbering remain Documents-owned.
8. App API types and views display configuration state and document references without exposing secrets or allowing unsupported policy activation.
9. API PHP tests, MySQL clean-install tests, App type-check and relevant UI contract tests pass on exact candidate SHAs.

## 10. Deferred work

Opening-balance import, tax determination, FX posting/revaluation, operational invoice/receipt subledgers, cash/bank, inventory, assets, procurement, payroll posting, and source adapters remain in their later frozen phases. No production deployment is part of Phase 01.
