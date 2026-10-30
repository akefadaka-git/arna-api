# ARNA Canonical Chart of Accounts v1.0.0

**Status:** Implemented on a review branch; accounting-policy and Ethiopian tax review remain **PENDING OWNER APPROVAL**. This document is not a tax opinion and does not claim regulatory approval.

## Canonical source

- Register: mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php
- Seeder: mases_core/src/Domains/Finance/Database/Seeders/FinanceChartOfAccountsSeeder.php
- Metadata migration: mases_core/src/Domains/Finance/Database/Migrations/2026_10_28_120000_add_canonical_coa_metadata_to_ledger_accounts.php
- Model: mases_core/src/Domains/Finance/Models/LedgerAccount.php
- Version: 1.0.0

The register contains 79 canonical codes. Each row specifies code, parent code, English and Amharic labels, account type, normal balance, posting eligibility, control-account designation, domain mapping, and a default tax category.

## Tax classification semantics

Allowed default tax categories:
- taxable: a default classification for supplies expected to be taxable, subject to applicable law and transaction facts.
- non_taxable: not subject to output VAT as a taxable supply, where confirmed by the applicable rule.
- exempt: exempt treatment, where a specific legal basis applies.
- zero_rated: taxable supply at a zero rate only where legally eligible and evidence is retained.
- out_of_scope: transaction is outside the relevant VAT scope; not interchangeable with exempt or zero-rated.
- undetermined: tax treatment cannot be safely inferred from the account. A transaction-level tax code, counterparty/supply facts, and supporting evidence must determine the treatment before tax reporting.

An account is not itself a taxable supply. Revenue and expense accounts may contain transactions with different VAT treatments. Accordingly, undetermined is an intentional fail-safe default where classification requires transaction or tax-policy facts. The current v1 register assigns a default category to every account; it does not assert that every account has been legally classified as taxable or non-taxable.

## Owner review disposition — 2026-10-28

**Structural baseline:** accepted as the proposed v1 account structure for continued implementation review. This is not authorization to post production journals or activate subledger adapters.

**Tax defaults:** conservative routing defaults only. The register's sales, service, and procurement/fulfillment revenue defaults are provisionally taxable; actual transaction treatment must be determined from the supply and approved tax code. Loan interest, wallet fees, commissions, supplier costs, inventory and most operating expenses remain undetermined where facts drive treatment. Account balances such as recoverable input tax are out of scope for supply classification; account 1190 is categorized out_of_scope.

**Control ownership:** proposed domain ownership is Finance/Treasury for cash and bank, Wallet + Finance for wallet settlement/customer balances, Loan + Finance for loan receivables and impairment, Procurement + Finance for supplier balances, People/Payroll + Finance for payroll, Shareholder + Finance with Governance authorization for capital/distributions, Finance/Tax for tax balances, and Finance/Consolidation for intercompany accounts. Each control account requires a defined reconciliation owner and evidence schedule before adapter activation.

**Approval boundary:** final Ethiopian tax treatment and legal classification require review by a qualified accounting/tax professional and explicit Owner approval. No tax classification should be inferred solely from the account name. No production activation or adapter enablement is authorized by this review.

## Governance and activation

1. Owner authorization permits preparation and implementation in this branch; it does not substitute for review by the responsible Ethiopian tax/accounting professional.
2. Before production activation, approve the legal-entity accounting policy, tax-category defaults, control-account ownership, and subledger mappings.
3. Run migrations before invoking `php artisan db:seed --class='MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceChartOfAccountsSeeder'` in an approved environment.
4. The seeder is deliberately not wired into DatabaseSeeder: that entry point invokes the legacy MasesCoreSeeder orchestrator, and automatic activation is not authorized by this change.
5. Seeder processes active legal entities only, fails if no active legal entity exists, refuses to overwrite any matching non-system account, and does not create opening balances.
6. Re-running the seeder updates only rows marked is_system_account=true for the same tenant/legal entity/code. Do not change posted journal history to match a new CoA version; future versions require a controlled migration/reclassification plan.

## Account control assumptions

- Parent/group rows are non-posting.
- Control accounts identify their owning domain for reconciliation; they do not automatically enable a subledger adapter.
- Wallet customer balances are liabilities only if the legal and operational arrangement makes ARNA responsible for funds held for customers.
- Loan principal movements are not revenue. Loan interest and fees require their own product/legal tax assessment.
- Shareholder register changes do not create cash or revenue automatically.
- Opening balances are intentionally absent.

## Verification record

- Source register statically inspected from GitHub: 79 rows, 79 unique codes, parent rows precede children, and each row contains a tax category.
- Repository tests: NOT RUN (per Owner's no-local-tests constraint).
- CI/workflows: NOT RUN.
- Production activation: NOT RUN.