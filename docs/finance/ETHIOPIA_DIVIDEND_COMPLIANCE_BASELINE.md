# Ethiopia Dividend Compliance Baseline

Status: implementation baseline; not a legal opinion or a substitute for company-specific legal and tax review.
Applies to: annual shareholder profit distributions in Finance.
Currency baseline: ETB. The tax and company-law rules are effective-dated; never overwrite historical distribution terms.

## 1. Governing legal baseline

| Control | Baseline | System enforcement |
|---|---|---|
| Lawful source of dividend | Commercial Code Proclamation 1243/2021, Article 438(1)-(2): dividends may only be paid from net profits shown in an approved balance sheet; unlawful distributions are fictitious dividends and can create civil/criminal liability. | Require a recorded approved financial-statement/balance-sheet reference and Finance eligibility certification before approval/accrual. Never infer distributable profit from cash balance or bank balance. |
| General-meeting authority | Article 438(3): the general meeting determines payment method and fixes payment date. | Store resolution reference/date, payment method and due date on each annual distribution; require evidence of the resolution. |
| Payment deadline | Article 438(3): payment date may not exceed four months from the profit-distribution resolution. | API rejects due dates later than resolution date + four calendar months. |
| Creditor status | Article 438(5): shareholder becomes a creditor for the dividend from the fixed payment date. | Recognize the approved payable and aging/overdue status consistently with the approved resolution and accounting policy; do not equate payable creation with cash settlement. |
| Vary/cancel | Article 438(4): the general meeting may for good reason vary/cancel before the payment deadline. | Preserve the original approved snapshot; require an authorized, reasoned resolution and compensating/reversal entries. No destructive edit of approved records. |

## 2. Income-tax configuration

The former 10% dividend withholding rate is obsolete for distributions governed by the amended regime. Income Tax Amendment Proclamation 1395/2025 amends the Federal Income Tax Proclamation; current professional tax summaries report a 15% dividend withholding rate for resident and non-resident recipients, effective from 8 July 2025. Configure 15% (1,500 basis points) as the effective-dated standard baseline only where the amended law applies.

Important: do not apply one unconditional rate to every shareholder. Before each distribution is approved, resolve and evidence the recipient-specific treatment, including:
- tax residency and recipient legal form;
- applicable domestic exemption or special treatment;
- valid double-taxation treaty claim, residence certificate and beneficial-ownership evidence where relevant;
- whether the shareholder is a qualifying corporate recipient or otherwise exempt;
- the law/rate effective on the legally relevant payment/derivation date;
- tax authority filing, remittance and certificate obligations.

The distribution-level withholding_rate_bps field is a temporary explicit override/input, not a substitute for recipient-level tax determination. Until recipient tax classification and evidence are implemented and reviewed, distributions with mixed/unknown recipient tax status must not be approved for payment. Do not silently default to zero or inherit a prior year's rate.

Tax configuration must be versioned by effective date and legal source, approved by a Finance tax officer, and snapshotted with every distribution. A treaty/exemption must be supported by evidence, expiry date and reviewer; expired or missing evidence falls back to the statutory treatment only after tax review.

## 3. Accounting and audit framework

- Apply the reporting framework required for the legal entity under Financial Reporting Proclamation 847/2014 and AABE decisions: IFRS or IFRS for SMEs, as applicable to the entity's classification. Do not hard-code one framework for every tenant/entity.
- Keep gross dividend expense/distribution, shareholder net payable and withholding-tax payable separately traceable. Use approved chart-of-account mappings; never invent account IDs.
- Require balanced journals, valid open accounting periods, legal-entity/tenant ownership, source provenance, idempotency and immutable posting audit trails.
- A dividend payable is not proof of payment. Settlement requires bank/provider confirmation and reconciliation; failed payments remain outstanding and reversals require compensating entries.
- Preserve calculation inputs, eligibility ledger snapshot, allocation algorithm/version, tax decision/evidence, approvals, journal references, payment references and reconciliation evidence.
- Enforce maker-checker separation for calculation/approval, account mapping, tax configuration, payment release and reconciliation.
- Restrict access by tenant, legal entity and least privilege. Log privileged reads/changes and prevent cross-tenant disclosure.

## 4. Annual distribution settings

Each distribution must independently record:
- legal entity and financial year;
- GA resolution identifier, date, evidence and payment method;
- approved balance-sheet / financial-statement reference and distributable-profit certification;
- declared gross amount and currency;
- record date and immutable shareholder entitlement snapshot;
- share-class rights/weights as approved by governing documents;
- recipient-level tax classification, rate, legal basis and evidence;
- payment due date (no later than four months after resolution);
- account mappings, approval chain, journal and payment/reconciliation references.

No previous-year rate, date, share-class weight, allocation rule or tax exemption may be inherited without an explicit new-year approval. Changes after approval require a new resolution/controlled amendment and full audit history.

## 5. Release blockers that must remain fail-closed

1. Approved balance-sheet reference and distributable-profit certification are not yet captured by the current API model.
2. Recipient-specific tax treatment (including the amended 15% baseline, treaty and exemption evidence) is not yet implemented; the current distribution-level bps field is insufficient for mixed recipient populations.
3. Dividend accrual journal, withholding liability/remittance ledger, payment batch lifecycle and bank reconciliation remain to be implemented and tested.
4. Share-class weighting remains disabled until approved class rights/weights are configured.
5. Production payment execution remains disabled until the bank/provider adapter, credentials, signing controls and reconciliation process are approved.

## 6. Authoritative references reviewed

- Commercial Code of Ethiopia, Proclamation 1243/2021, Article 438: https://www.wipo.int/wipolex/en/legislation/details/21658 and English text: https://ethiodata.et/wp-content/uploads/2023/01/Commercial-Code-of-Ethiopia-English-Proclamation-No.-1243_2021.pdf
- Financial Reporting Proclamation 847/2014 (in force): https://justice.gov.et/en/law/financial-reporting-proclamation/
- AABE accounting and auditing standards: https://aabe.gov.et/accounting-and-auditing-standards-2/
- Income Tax Amendment Proclamation 1395/2025 (Ministry of Justice, in force): https://justice.gov.et/en/law/proclamation-to-amend-federal-income-tax-proclamation-no-1395-2024/
- Ministry of Finance tax directive publication page: https://www.mofed.gov.et/mof-directive/tax-directive/
- Current tax rate cross-check (professional summary, reviewed 15 July 2026): https://taxsummaries.pwc.com/ethiopia/corporate/withholding-taxes

Legal/tax counsel and the entity's licensed accountant must validate applicability to the specific company, shareholder classes, treaty claims and distribution year before production approval.
