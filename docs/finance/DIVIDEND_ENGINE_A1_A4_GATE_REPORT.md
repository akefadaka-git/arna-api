# Dividend Engine A1–A4 Gate Report

**Assessment date:** 2026-11-03  
**Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`  
**Deployment:** A5 explicitly excluded

## Executive status

A1 and A2 design/compliance baseline work is recorded. A3 is partially implemented across the existing annual distribution engine and the new compliance/tax-control increment. A4 is not closed for the new exact heads because dividend-specific automated tests, integrated MySQL verification, frontend/API integration, accessibility/device acceptance and concurrency acceptance have not been run against those heads.

**Overall gate status: PARTIAL — NOT PRODUCTION READY.** Do not represent the current engine as fully closed or permit production dividend release.

## A1 — Assessment and requirements

**Status: PASS WITH OPEN LEGAL/ACCOUNTING VALIDATION**

- Commercial Code Proclamation 1243/2021, Article 438 baseline captured: approved-balance-sheet net profit, General Meeting authority, payment deadline, creditor status and controlled variation/cancellation.
- Financial Reporting Proclamation 847/2014 and AABE framework applicability captured; entity-specific IFRS / IFRS for SMEs classification remains to be confirmed.
- Income-tax configuration is designed as recipient-specific and effective-dated. The current implementation does not make a blanket tax determination for all shareholders.
- Legal counsel and the entity's licensed accountant must validate the actual company, articles, shareholder classes, treaty claims and tax-year treatment.

## A2 — Read-only design and contract

**Status: PASS WITH OPEN ACCOUNT-MAPPING/PROVIDER CONTRACTS**

- Annual distribution and shareholder eligibility snapshot contract exists.
- Pro-rata allocation is limited to a single comparable share class; multi-class distributions fail closed pending approved class rights and weights.
- New API increment adds approved balance-sheet evidence, distributable-profit amount, GA resolution evidence, independent profit certification and per-shareholder tax assessment fields.
- Remaining accounting mapping, bank/provider, tax-remittance and reconciliation contracts are not complete.

## A3 — Implementation

**Status: PARTIAL**

Existing API PR #81 and App PR #33 contain the initial distribution engine and shareholder statement portal. Additional draft increments:

- API PR #83: https://github.com/akefadaka-git/arna-api/pull/83
- App PR #35: https://github.com/akefadaka-git/arna-app/pull/35

Implemented in the new increments:
- Evidence fields for approved balance sheet, distributable profit, GA resolution, payment method and reporting framework.
- Independent Finance profit certification.
- Recipient-specific tax assessments with treatment, rate, legal basis, evidence and expiry.
- Per-shareholder tax rate snapshots in distribution allocation items.
- Separation between recipient tax assessor and final distribution approver.
- Shareholder portal visibility for cash paid, penalty offsets and remaining payable.

Still missing:
1. Dividend accrual journal and separate withholding-tax liability journal, with independently approved entity-scoped account mappings.
2. Withholding tax return/remittance records, certificates and reconciliation.
3. Payment batches, provider/bank adapter, maker-checker release, idempotent execution, failure/retry/return handling, confirmation evidence and bank reconciliation.
4. Controlled GA amendment/cancellation and compensating journal workflow.
5. Finance administration UI for distribution creation, profit certification, recipient tax assessment, approval, journal and payment monitoring.
6. Share-class weighting configuration and validated legal rights model.
7. Dividend-specific audit events, reporting, statements/PDF and operational exception queues.

## A4 — Verification

**Status: NOT CLOSED ON THE NEW EXACT HEADS**

Prior evidence for the underlying PR #81 API head `bd42a08293905dd7200413c07fbfaab9a12119f4`:
- PHP lint and public verification tests: PASS — run 41624005007.
- Fresh migration/seed regression: PASS — run 41624004986.
- Governance authorization tests: PASS — run 41624004996.
- Tenant-isolation suite: PASS — run 41624004976.
- Runtime route/authorization audit: PASS — run 41624005039.
- Later clean-install MySQL workflow: PASS — run 41649343084; exact-target checkout and Finance regressions, migration idempotence, seed flow and table checks completed.
- Later tenant-isolation run 41649342961: CANCELLED.
- Earlier full MySQL run 41624004977: FAILED at expected core/domain table verification; later clean-install run passed but does not establish dividend-specific acceptance.
- Dividend-specific service/payment tests: NOT ADDED / NOT RUN in PR #81.

Prior App PR #33 head `36eb8e8204ee38d02004b649cd766917c7a17e16`:
- Frontend verification/build: PASS — run 41623666708.
- Device, accessibility and end-to-end API integration acceptance: NOT RUN.

New exact heads:
- API PR #83: `bb92b413a5233d0c525517b9cf11c09f02988658` — no workflow runs/status checks returned at report time.
- App PR #35: `6ec7cfa043a438d1db7de3df6b1397d9eb915842` — no workflow runs/status checks returned at report time.

Required A4 work: PHP lint, migration ordering and clean-install MySQL, recipient-tax and profit-certification feature tests, tenant isolation, journal balancing/idempotency, payment lifecycle and reconciliation tests, frontend type-check/build, API contract integration, mobile/tablet/desktop, keyboard/screen-reader/WCAG 2.2 AA, and concurrent approval/payment replay tests on the final exact heads.

## A5 — Deployment

**NOT STARTED — SEPARATE GATE.** No production deployment, secrets configuration, bank/provider credentials, live payment execution or production data mutation is authorized by this completion scope.

## Release decision

**NO-GO for production dividend distribution or payment.** Keep release controls fail-closed until A3 items 1–7 and A4 exact-head acceptance are complete and evidenced.