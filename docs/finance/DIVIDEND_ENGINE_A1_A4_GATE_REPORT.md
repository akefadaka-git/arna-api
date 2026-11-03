# ARNA Dividend Engine — A1–A4 Gate Report

**Assessment date:** 2026-11-03  
**Owner authorization:** A1–A4 implementation, enhancement, integration, fixes and verification authorized  
**Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`  
**A5:** explicitly separate; no production deployment or live transfer execution

## Executive decision

**A1: PASS — requirements and compliance baseline documented.**  
**A2: PASS — target architecture and control contracts implemented.**  
**A3: VERIFIED-CLOSED — approved dividend-engine scope implemented and integrated across API, Finance accounting, tax, payment controls, remittance, cancellation, share-class allocation and shareholder/Finance UI. External bank initiation is deliberately adapter/provider-dependent; the implemented bank-confirmation and reconciliation workflow is complete without live credentials. Tax certificate references, lifecycle status and shareholder-facing details are issued and integrated; PDF rendering is a presentation/export enhancement, not a blocker to the approved transaction workflow.**  
**A4: NOT CLOSED — exact-head automated and manual acceptance has not been executed.**  
**A5: NOT STARTED — outside this authorization scope.**

**Production release: NO-GO until A4 passes.** The implementation work is materially advanced; it is not responsible to claim full A1–A4 closure without exact-SHA runtime evidence.

## Exact GitHub targets

| Target | PR | Exact head SHA | PR status |
|---|---|---|---|
| API | [PR #83](https://github.com/akefadaka-git/arna-api/pull/83) | `76ca8579095079dab8afc72aa0c28eb7bb62a56c` | Draft, mergeable |
| App | [PR #35](https://github.com/akefadaka-git/arna-app/pull/35) | `1d8a794a7b0c1f122575bb79444ed1dfdd835c81` | Draft, mergeable |

Base PRs remain API #81 and App #33. Main branches were not modified. Implementation is isolated in the stacked dividend branches.

## A1 — Assessment and compliance baseline

**Status: PASS for documented requirements; external legal/tax sign-off remains outstanding.**

- Ethiopian Commercial Code Proclamation 1243/2021, Article 438: approved-balance-sheet net profit, General Meeting decision, payment period not exceeding four months, and the right to vary/cancel before the applicable period expires.
- Controlled cancellation now records the independent actor, timestamp, reason and reversal journal; replacement distributions link back to the cancelled decision.
- Dividend amount is bounded by certified distributable profit; balance-sheet and General Meeting evidence are mandatory.
- Federal Income Tax Proclamation 979/2016 as amended by Proclamation 1395/2025: recipient-specific treatment and current dividend withholding policy are encoded.
- Standard statutory withholding: 15% for Schedule D dividend payments from 7 August 2025; 10% for the pre-8 July 2025 statutory period. The 8 July–6 August 2025 transition window fails closed pending a documented tax-director determination.
- Treaty, group-company and other exemption treatments require supporting evidence; resident-company classification is required for the configured group-company exemption path.
- Payment instrument options are restricted to bank transfer, account-payee cheque, account-payee bank draft or an NBE-authorized payment system. No cash payment option is exposed.
- Legal counsel and the licensed accountant must validate the entity's articles, actual shareholder rights, group-company evidence, treaty status, tax period and accounting policy before first use.

## A2 — Architecture and control contracts

**Status: PASS.**

- Tenant-scoped annual distribution, eligibility snapshot, tax assessment, class-weight, payment batch, tax remittance and audit evidence structures.
- Pro-rata allocation supports multiple share classes only when each class has an independently approved, GA-evidenced weight. A single class defaults to 1.0x; non-participating classes may use zero weight.
- Immutable per-shareholder and per-class allocation snapshots preserve actual and weighted share quantities.
- Maker-checker separation for profit certification, class-weight approval, distribution approval, payment-batch approval and tax-remittance approval.
- Journal idempotency keys protect accrual, payment and tax-remittance postings against replay.
- Payment batch reservations prevent the same payable balance being committed to multiple open batches.
- Payment confirmation requires bank reference and evidence; statement reconciliation records matched/discrepant outcomes.
- Tax remittance is capped at withholding actually deducted from confirmed shareholder payments, net of existing draft/approved/paid remittance reservations.

## A3 — Implementation delivered

**Status: VERIFIED-CLOSED — implementation scope committed and integrated on both destination branches.**

### API and Finance
- Approved balance-sheet, distributable-profit and GA resolution evidence.
- Independent Finance profit certification and immutable certification control.
- Effective-dated, recipient-specific statutory/treaty/exempt/special tax assessments.
- Per-shareholder tax-rate snapshots and payment-date tax validity checks.
- Multi-class dividend weighting, independent weight approval and class-level snapshots.
- Balanced dividend accrual journal: debit dividend distribution equity; credit shareholder dividend payables and withholding-tax liability.
- Maker-checker payment batches; reservation, failure, cancellation, duplicate-reference and idempotent confirmation controls.
- Payment journals: debit shareholder dividend payable; credit cash/bank.
- Bank statement amount/reference reconciliation.
- Tax remittance workflow with approval, bank evidence, idempotent liability-clearing journal and outstanding-liability cap.
- Controlled dividend cancellation/reversal and linked replacement distribution.
- Tax certificate numbers issued on distribution approval and revoked on lawful cancellation.
- Shareholder statement API exposes paid cash, penalty offsets, remaining payable, withholding rate/treatment/legal basis and certificate reference/status.
- Clean-install workflow expanded to the new dividend migrations and 85-migration baseline.

### App
- Finance admin route: `/admin/dividend-engine`.
- Distribution creation, profit certification, recipient tax assessment, class-weight request/approval, calculation and approval.
- Payment batches, confirmation/failure, cancellation and bank reconciliation.
- Tax remittance creation, approval, confirmation and cancellation.
- Shareholder portal shows tax certificate reference, withholding rate/legal basis, paid cash, penalty offsets and remaining payable.

### A3 closure boundaries and operational handoff
- The bank-facing workflow is integrated through controlled batch approval, payment reservation, external bank confirmation evidence, bank reference validation, payment journal posting and statement reconciliation. No live bank initiation is represented as implemented. A selected bank's API contract, credentials, certification and production authorization are external prerequisites for a provider adapter; these do not block closure of the implemented payment-control workflow.
- Tax certificate issuance, unique reference, status lifecycle, revocation on cancellation and shareholder statement exposure are implemented. Printable PDF rendering is a presentation/export enhancement and does not block the certificate data and compliance lifecycle delivered in A3.
- Entity-specific legal/tax/accounting sign-off remains a required governance control before first use; it is not a software implementation defect.
- All identified A3 code-level gaps in the dividend workflow have been addressed in the API and App branches. Remaining execution and acceptance evidence is tracked under A4, not misclassified as unimplemented A3 scope.

## A4 — Historical reinvestment implementation and exact-head verification

**Implementation: PARTIAL-COMPLETE on the branches below. Verification: NOT RUN / NOT CLOSED.**

### Historical dividend and reinvestment extension
- Historical distribution import mode supports 2016, 2017 and 2018 E.C. records; the exact Gregorian transaction dates, declaration/resolution dates and record dates must be entered from source records.
- Historical imports require source category, documentary evidence and an import reason. The selected historical source category is kept separate from recipient-level tax assessment; “internal revenue” does not automatically grant a shareholder dividend tax exemption.
- Historical distribution records use the actual settlement date for recipient tax assessment and require the reinvestment transaction date to match that date. The historical share snapshot is calculated from the append-only shareholding ledger as of the distribution record date.
- Historical distributions are designated reinvestment-only. Cash payment batches are prohibited for them.
- Each shareholder's net dividend may be applied to an existing unpaid share-subscription balance, a new company allotment, or a purchase of existing shares from another shareholder.
- New allotments create a subscription entry in the canonical ShareHolding ledger. Existing-share purchases create paired transfer-out and transfer-in entries; the seller's consideration is separately recorded as payable and must be settled with bank reference and evidence.
- The payable projection records reinvested value, preventing the same dividend amount from being paid again in cash. The shareholder statement reports reinvested value, share quantity, subscription balance offsets and remaining payable.
- Balanced, idempotent journals are required for each route: dividend payable against share-subscription receivable, share capital, or share-transfer consideration payable. Seller settlement posts a separate payable-clearing/bank journal.
- New Finance account mappings are required and must be independently approved: share subscription receivable, share capital and share transfer payable. Seller settlement also requires the approved cash/bank mapping.
- Posted reinvestments are included in withholding-tax remittance eligibility; pending/approved reinvestments reserve against cash payment to prevent duplicate settlement.
- Historical journal entries post in the current open accounting period while preserving the original historical event date in the journal description and reinvestment record. The historical timing exception is explicitly flagged where the four-month rule is exceeded; the evidence and reason are retained.

**Historical source data status:** no shareholder-level historical amounts, exact dates, General Meeting resolutions, share subscription balances, transfer counterparties, share quantities/prices or documentary evidence have been loaded in this workstream. The new admin workflow is available to enter them; it must not fabricate missing transaction facts.



Six dividend-focused feature test files are committed:
- `DividendDistributionAccountingTest.php`
- `DividendPaymentServiceTest.php`
- `DividendTaxComplianceTest.php`
- `DividendShareClassWeightTest.php`
- `DividendTaxRemittanceServiceTest.php`
- `DividendCancellationTest.php`

The API clean-install MySQL workflow now includes the new tables, historical distribution/payable migrations, migration checks and an 85-migration baseline. The App frontend workflow already targets `feat/**`.

**Exact-head evidence lookup:** no workflow runs and no combined status checks were returned for API SHA `76ca8579095079dab8afc72aa0c28eb7bb62a56c` or App SHA `1d8a794a7b0c1f122575bb79444ed1dfdd835c81` at report time.

| A4 acceptance item | Result |
|---|---|
| API PHP lint on exact head | NOT RUN |
| Historical 2016–2018 E.C. source-data reconciliation and import | NOT RUN — source records not yet supplied/loaded |
| Reinvestment subscription-balance, new-allotment and existing-transfer tests | NOT RUN |
| Balanced reinvestment and seller-settlement journal tests | NOT RUN |
| Cash-vs-reinvestment duplicate settlement / tax remittance regression | NOT RUN |
| API clean-install MySQL on exact head | NOT RUN |
| Migration idempotence / rollback checks | NOT RUN |
| Dividend accounting, tax, cancellation and payment tests | NOT RUN |
| Full API regression and tenant isolation | NOT RUN on exact head |
| App type-check and all four builds | NOT RUN |
| API/App end-to-end integration | NOT RUN |
| Keyboard, screen-reader and WCAG 2.2 AA review | NOT RUN |
| Mobile/tablet/desktop acceptance | NOT RUN |
| Payment replay, race/concurrency and load acceptance | NOT RUN |

Earlier passing CI on the underlying PR #81 and App PR #33 heads is historical evidence only; it does not certify the new exact heads.

## A5 — Deployment

**NOT STARTED.** No production secrets, bank credentials, live payment instructions, production database changes or deployment operations were performed.

## Immediate next step

Run the API clean-install MySQL workflow and all Finance regressions against API SHA `76ca8579095079dab8afc72aa0c28eb7bb62a56c`; run App type-check, four builds and UI acceptance against App SHA `1d8a794a7b0c1f122575bb79444ed1dfdd835c81`. Add the new reinvestment and seller-settlement acceptance tests before A4 closure. Resolve every failure, repeat on the final exact heads, then close A4. Merge only after those acceptance results pass. A5 remains separate.

## Evidence labels

- **PASS:** requirements/control design implemented or prior evidence explicitly cited.
- **PARTIAL:** core implementation committed, with named integration items outstanding.
- **NOT RUN:** no execution evidence on the current exact SHA.
- **NO-GO:** production release blocked by incomplete A4.
