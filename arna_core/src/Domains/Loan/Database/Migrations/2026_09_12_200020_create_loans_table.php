<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loans` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * The Loan aggregate root. Each row records one loan: its identity (which
 * tenant, which borrower, which product), its lifecycle state, its frozen
 * contractual terms (principal, term, currency, policy version), and its
 * outstanding projections.
 *
 * The table is the Loan domain's authoritative record of the CONTRACTUAL
 * side of a loan. It does NOT hold the monetary ledger — that belongs to
 * the Wallet domain (Phase 2B), which records obligations, payments,
 * allocations, and entries against the borrower's wallet. The two sides
 * are reconciled by `LoanReconciliationService` (A.32), which compares the
 * loan-linked Wallet obligations against this table's projections.
 *
 * The separation is the Phase 2C implementation of decision B2 (hybrid
 * ledger ownership):
 *
 *     Loan domain owns:  contractual schedule, lifecycle, projections
 *     Wallet domain owns: actual obligations, payments, ledger entries
 *
 * =============================================================================
 * Direction and scope (D129, Q8)
 * =============================================================================
 * The domain is strictly company-as-lender → shareholder-as-borrower.
 * There is no member-to-member lending, no shareholder-to-company lending,
 * and no cross-tenant lending. The lender is the company by construction;
 * the borrower is `borrower_shareholder_id`. No `lender_..._id` column
 * exists because no other lender is possible.
 *
 * The loan is single-tenant scoped: `tenant_id` is required and every
 * lookup is scoped to the tenant that owns the loan. Cross-tenant
 * references (borrower, product, policy) resolve to `null` at the
 * repository layer, which the controller translates into a 404.
 *
 * The loan's currency (set at disbursement time) equals the borrower's
 * Wallet currency. Q8's single-currency invariant makes the Wallet the
 * authoritative source of that fact; the loan's `currency` column records
 * the value copied from the Wallet so that a disbursed loan remains
 * self-describing in reports and audit.
 *
 * =============================================================================
 * Status
 * =============================================================================
 * `status` stores the `LoanStatus` enum value (A.2). The 12-state
 * vocabulary is frozen by the C4 decision:
 *
 *     draft, submitted, under_review, approved, rejected,
 *     disbursed, active, in_arrears, restructured,
 *     written_off, settled, closed
 *
 * The default is `'draft'`, the state a loan is created in. State
 * transitions are performed exclusively by `LoanStateMachine` (A.26),
 * which applies guards, permissions, and event dispatch on top of the
 * structural transition matrix declared by the enum.
 *
 * The column is VARCHAR(32), not a MySQL ENUM. Adding a state is a Change
 * Request that updates `LoanStatus`; a MySQL ENUM would require a schema
 * migration for every value change. This mirrors the Phase 2A and Phase 2B
 * conventions.
 *
 * The `loan_status_histories` table (migration A.14) records every
 * transition with a timestamp, the from/to states, the actor, and an
 * optional transition reason. It is the authoritative log of the loan's
 * lifecycle; the `status` column is the CURRENT state, not the history.
 * The rejection reason, for example, is recorded there — not on this row.
 *
 * =============================================================================
 * The four persisted projections (per the C5 freeze)
 * =============================================================================
 * Four columns carry the Loan domain's authoritative view of what the
 * borrower still owes and has settled:
 *
 *     settled_amount          — running total of amounts applied to the
 *                               loan's obligations (from allocations).
 *                               NOT NULL, default '0.0000'.
 *
 *     outstanding_principal   — the un-paid portion of the original
 *                               principal. Nullable until disbursement
 *                               initializes it.
 *
 *     outstanding_interest    — accrued-but-un-paid interest. Nullable
 *                               until the first interest accrual sets it.
 *
 *     outstanding_penalty     — accrued-but-un-paid penalty. NOT NULL,
 *                               default '0.0000'; penalties begin at
 *                               zero and grow.
 *
 * These columns are PERSISTED, TRANSACTIONAL projections, not
 * recalculated on read. Every operation that changes them (repayment,
 * interest accrual, penalty accrual, restructuring, write-off) updates
 * them inside the same transaction that changes the underlying state.
 * No controller, resource, or repository recalculates them outside a
 * service's transaction boundary.
 *
 * The reconciliation invariant (per the C5 freeze, corrected in the
 * Phase 2C Final Reconciliation) is:
 *
 *     Σ(wallet_obligations.outstanding_amount
 *         WHERE reference_type = 'Loan'
 *           AND reference_id   = (string) loans.id)
 *       ==
 *     outstanding_principal + outstanding_interest + outstanding_penalty
 *
 * The invariant is verified by `LoanReconciliationService` (A.32) at every
 * financial transaction boundary and on demand. It does NOT compare against
 * the wallet's `current_balance`; the wallet balance includes non-loan
 * obligations and adjustments and is a Wallet-domain concern, not a
 * Loan-domain reconciliation target.
 *
 * =============================================================================
 * Scheduled-amount columns
 * =============================================================================
 * Three columns record the schedule's summary as computed by
 * `AmortizationCalculator` (A.6) at approval time:
 *
 *     monthly_installment_amount  — the fixed M used for every non-final
 *                                   installment.
 *     total_scheduled_interest    — sum of the schedule's expected_interest
 *                                   across all installments.
 *     total_scheduled_repayment   — sum of the schedule's expected_total
 *                                   across all installments.
 *
 * The three are NULLABLE because they are set only when a schedule is
 * generated, which happens at approval time. Before approval, a loan in
 * `draft`, `submitted`, or `under_review` has no schedule and these
 * columns are NULL.
 *
 * The schedule's PER-INSTALLMENT rows live in `loan_installments`
 * (migration A.10). The three columns here are a denormalized summary for
 * reporting and for the loan's public representation; they are
 * transactional with the schedule's generation, not derived on read.
 *
 * =============================================================================
 * Policy freeze (B6, Extra 2)
 * =============================================================================
 * `frozen_policy_version_id` records the specific `loan_product_rules`
 * policy version that was in effect at the moment the loan was approved.
 * The value is copied from `loan_products.product_rules_policy_version_id`
 * at the under_review → approved transition and never changes thereafter.
 *
 * The freeze implements the B6 / Extra-2 decision: a loan's financial
 * rules are fixed at approval, and a subsequent publication of a new
 * `loan_product_rules` version does not retroactively change the terms of
 * a loan already originated. This mirrors the Phase 2B pattern of
 * freezing policy versions on the wallet at creation.
 *
 * The column is NULLABLE because before approval there is no frozen
 * version. The `LoanApprovalService` (A.25) is the sole writer.
 *
 * The type compatibility of the referenced policy (that
 * `policy_type = 'loan_product_rules'`) is enforced by the service that
 * assigns the FK; the schema does not enforce it.
 *
 * =============================================================================
 * Dates and actors
 * =============================================================================
 * The lifecycle dates are set as the loan progresses:
 *
 *     application_date   — NOT NULL. Set at row creation (via
 *                          `useCurrent()`). Records the moment the loan
 *                          application was initiated. The submission
 *                          moment (the draft → submitted transition) is
 *                          recorded separately in
 *                          `loan_status_histories.occurred_at`.
 *
 *     decision_date      — nullable. Set at the under_review → approved
 *                          or under_review → rejected transition.
 *
 *     disbursement_date  — nullable. Set at the approved → disbursed
 *                          transition, after the Wallet principal
 *                          obligation has been recorded.
 *
 *     maturity_date      — nullable. Set at disbursement time to the
 *                          schedule's final installment's due date.
 *
 *     closed_at          — nullable. Set at the settled → closed
 *                          transition.
 *
 * The actor columns mirror the dates:
 *
 *     created_by         — nullable. The user who created the draft.
 *     approved_by        — nullable. The user who approved the loan.
 *     disbursed_by       — nullable. The user who disbursed the loan.
 *
 * The user who REJECTED a loan, or who performed any other lifecycle
 * action, is recorded on the corresponding `loan_status_histories` row
 * via its `actor_user_id` column. This avoids adding a bespoke actor
 * column to the loan row for every possible transition; the status
 * history is the complete and authoritative record.
 *
 * All three user FKs use `nullOnDelete()`: a hard-deleted user detaches
 * from the loan, which survives with a null actor. This mirrors the Phase
 * 2A `shareholders.user_id` and Phase 2B `wallet_*.created_by`
 * conventions.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Six foreign keys:
 *
 *     tenant_id                 → tenants.id             RESTRICT
 *     borrower_shareholder_id   → shareholders.id        RESTRICT
 *     loan_product_id           → loan_products.id       RESTRICT
 *     frozen_policy_version_id  → versioned_policies.id  RESTRICT (nullable)
 *     approved_by               → users.id               SET NULL (nullable)
 *     disbursed_by              → users.id               SET NULL (nullable)
 *     created_by                → users.id               SET NULL (nullable)
 *
 * Q6 mandates RESTRICT / NO ACTION for every loan-owned financial FK: a
 * hard delete of a tenant, a borrower, a product, or a policy that a loan
 * references must fail loudly rather than cascade into the loan's
 * history. The three actor FKs use `nullOnDelete()` because the actor is
 * metadata, not an aggregate fact; a deleted user does not erase the loan.
 *
 * This mirrors the pattern established by `wallets` (Phase 2B, migration
 * 2B.49), where `tenant_id` and `shareholder_id` are RESTRICT and
 * `*_policy_version_id` are RESTRICT (nullable), while `created_by` on
 * the child tables is SET NULL.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Five composite indexes support the Phase 2C query patterns:
 *
 *   (tenant_id, status)                 — "list loans by state in tenant",
 *                                         the primary read pattern for
 *                                         the `/loans` index endpoint
 *                                         and for every status-filtered
 *                                         report.
 *
 *   (tenant_id, borrower_shareholder_id) — "list a borrower's loans",
 *                                         the primary read pattern for
 *                                         the shareholder portal and for
 *                                         the borrower-facing
 *                                         `loan.view_own` scope
 *                                         resolution.
 *
 *   (tenant_id, loan_product_id)        — "list loans by product", used
 *                                         by administrative reports and
 *                                         by the product's own aggregate
 *                                         queries.
 *
 *   (tenant_id, maturity_date)          — "list loans by upcoming
 *                                         maturity", used by the
 *                                         operational dashboard and by
 *                                         the settlement-reporting
 *                                         surface.
 *
 *   (tenant_id, application_date)       — "list loans by application
 *                                         order", the default sort of
 *                                         the `/loans` index endpoint.
 *
 * Each composite's leading column is `tenant_id`, so the tenant FK's
 * implicit index is satisfied by the first composite — MySQL reuses it
 * and does not create a duplicate single-column index on `tenant_id`.
 *
 * The remaining FK columns (`frozen_policy_version_id`, `approved_by`,
 * `disbursed_by`, `created_by`) have no 2B or 2C query pattern that
 * filters on them alone; MySQL provides their FK-supporting indexes
 * implicitly. No explicit single-column index is declared for them.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The state transition matrix. Enforced by `LoanStateMachine`
 *     (A.26), which composes `LoanStatus::canTransitionTo()` (A.2) with
 *     guards and permissions.
 *
 *   - The reconciliation invariant. Enforced by
 *     `LoanReconciliationService` (A.32) at transaction boundaries and
 *     on demand.
 *
 *   - The `currency` shape (three ASCII letters). Enforced by the
 *     service at write time.
 *
 *   - The relationship between `frozen_policy_version_id` and the
 *     product's current policy version. Enforced by
 *     `LoanApprovalService` (A.25), which copies the value from the
 *     product at approval time.
 *
 *   - The bounds validation (principal and term within the product's
 *     min/max). Enforced by `LoanApplicationService` (A.24) at
 *     submission time.
 *
 *   - The presence of a schedule before approval. Enforced by the
 *     approval workflow: `LoanScheduleService` (A.23) generates the
 *     schedule and populates the summary columns before the loan enters
 *     the `approved` state.
 *
 *   - The presence of `disbursement_date` and `maturity_date` for loans
 *     past the `approved` state. Enforced by `LoanDisbursementService`
 *     (A.26), which sets them as part of the disbursement transaction.
 *
 *   - No uniqueness constraint on any column or column combination. A
 *     loan has no natural key; two loans for the same borrower under the
 *     same product with the same principal and the same term are
 *     legitimately distinct applications. A future Change Request that
 *     introduces a human-readable loan number would add a UNIQUE on that
 *     column, not here.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loans` table. Foreign keys target the Phase 1
     * `tenants` and `users` tables, the Phase 2A `shareholders` table,
     * the Core Policy capability's `versioned_policies` table (CORE.2),
     * and the Phase 2C `loan_products` table (A.8) — all of which must
     * already exist when this migration runs.
     *
     * Migration ordering: A.8 (`loan_products`, prefix `200010`) runs
     * before this file (prefix `200020`). Both run after CORE.2
     * (`versioned_policies`, prefix `100001`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            //
            // Every loan belongs to exactly one tenant. Cross-tenant
            // references are not possible; the borrower and product FKs
            // below are resolved within the same tenant by the service
            // layer.
            //
            // On delete: restrict. A hard delete of the tenant must not
            // cascade into its loans (Q6, Architecture §17.3).
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Borrower.
            //
            // The shareholder who is the borrower. Per D129, the lender is
            // the company by construction; there is no `lender_..._id`
            // column.
            //
            // On delete: restrict. A hard-deleted shareholder must not
            // cascade into the loan and its schedule; the domain uses
            // SoftDeletes (2A.7), so a hard delete is an administrative
            // purge, which the FK blocks while a loan references the
            // borrower.
            //
            // The composite index (tenant_id, borrower_shareholder_id)
            // serves the "list a borrower's loans" query pattern; MySQL
            // additionally creates a single-column index for the FK.
            // -----------------------------------------------------------------
            $table->foreignId('borrower_shareholder_id')
                  ->constrained('shareholders')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('The shareholder who is the borrower');

            // -----------------------------------------------------------------
            // Loan product.
            //
            // The catalogue product this loan is originated under. The
            // product's rules (interest, term, arrears, collateral, fees)
            // live in the versioned policy registry; this FK identifies
            // the product identity only.
            //
            // On delete: restrict. A hard-deleted product must not cascade
            // into loans already originated under it.
            //
            // The composite index (tenant_id, loan_product_id) serves the
            // "list loans by product" query pattern; MySQL additionally
            // creates a single-column index for the FK.
            // -----------------------------------------------------------------
            $table->foreignId('loan_product_id')
                  ->constrained('loan_products')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('The product this loan is originated under');

            // -----------------------------------------------------------------
            // Lifecycle status.
            //
            // Stores a `LoanStatus` enum value (A.2). The default is
            // `'draft'`, the state a loan is created in.
            //
            // VARCHAR(32) accommodates every current value with headroom
            // (the longest is `under_review`, 12 characters). The column
            // is not a MySQL ENUM; adding a state updates the enum, not
            // the schema.
            // -----------------------------------------------------------------
            $table->string('status', 32)
                  ->default('draft')
                  ->comment('LoanStatus enum value; current lifecycle state');

            // -----------------------------------------------------------------
            // Principal.
            //
            // The loan's original principal amount. Set at application
            // time and immutable thereafter; a change to the principal is
            // a restructuring, which is recorded as a new schedule on the
            // same loan (or as a new loan, per the future restructuring
            // CR). DECIMAL(20,4) matches every monetary column on the
            // platform.
            // -----------------------------------------------------------------
            $table->decimal('principal_amount', 20, 4)
                  ->comment('Original principal; immutable after application');

            // -----------------------------------------------------------------
            // Term.
            //
            // The loan's term in months. Set at application time and
            // immutable thereafter (except through a restructuring, which
            // rewrites the schedule and updates this column transactionally
            // with the `loan_restructurings` row).
            // -----------------------------------------------------------------
            $table->integer('term_months')
                  ->comment('Term in months; frozen at approval, rewritable by restructure');

            // -----------------------------------------------------------------
            // Currency (Q8).
            //
            // ISO 4217 three-letter code. Nullable: not known until the
            // loan is disbursed, at which point the value is copied from
            // the borrower's Wallet currency (Q8 single-currency
            // invariant). Once set, the value is immutable.
            //
            // CHAR(3) matches the shape of `wallets.currency` and the
            // `currency` columns on the Wallet's obligations and payments.
            // -----------------------------------------------------------------
            $table->char('currency', 3)
                  ->nullable()
                  ->comment('ISO 4217 code; set at disbursement, copied from wallet');

            // -----------------------------------------------------------------
            // Frozen policy version (B6, Extra 2).
            //
            // Records the specific `loan_product_rules` policy version in
            // effect at the moment of approval. Copied by
            // `LoanApprovalService` (A.25) from the product's
            // `product_rules_policy_version_id` and never changed
            // afterwards. A subsequent publication of a new policy
            // version does not retroactively affect this loan.
            //
            // Nullable because the value is unset before approval.
            //
            // On delete: restrict. Versioned policies are immutable and
            // cannot be deleted; the RESTRICT is defensive against a
            // manual DB edit that would silently null the reference.
            //
            // Type compatibility (that the referenced policy's
            // `policy_type` is `'loan_product_rules'`) is NOT enforced by
            // the FK; the approval service performs the check.
            // -----------------------------------------------------------------
            $table->foreignId('frozen_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('VersionedPolicy reference frozen at approval; nullable');

            // -----------------------------------------------------------------
            // Schedule summary.
            //
            // The three columns below are a denormalized summary of the
            // schedule as computed by `AmortizationCalculator` (A.6) and
            // persisted by `LoanScheduleService` (A.23) at approval time.
            // The per-installment rows live in `loan_installments`
            // (A.10).
            //
            // Nullable because the values are unset before approval.
            // -----------------------------------------------------------------
            $table->decimal('monthly_installment_amount', 20, 4)
                  ->nullable()
                  ->comment('Fixed monthly installment; set at approval');

            $table->decimal('total_scheduled_interest', 20, 4)
                  ->nullable()
                  ->comment('Sum of scheduled interest across the schedule; set at approval');

            $table->decimal('total_scheduled_repayment', 20, 4)
                  ->nullable()
                  ->comment('Sum of scheduled principal + interest across the schedule; set at approval');

            // -----------------------------------------------------------------
            // Persisted projections (C5).
            //
            // The four columns below are the loan's authoritative
            // view of what the borrower still owes and has settled.
            // They are persisted, transactional projections — NOT
            // derived on read. Every operation that changes them
            // (repayment, interest accrual, penalty accrual,
            // restructuring, write-off) updates them inside the same
            // transaction that changes the underlying state.
            //
            // `settled_amount` and `outstanding_penalty` are NOT NULL
            // with defaults of `0.0000`; they begin at zero and grow.
            // `outstanding_principal` and `outstanding_interest` are
            // nullable because they are unset until the loan is
            // disbursed (principal) or first accrues interest.
            // -----------------------------------------------------------------
            $table->decimal('settled_amount', 20, 4)
                  ->default(0)
                  ->comment('Running total of amounts applied to the loan');

            $table->decimal('outstanding_principal', 20, 4)
                  ->nullable()
                  ->comment('Un-paid portion of the original principal; set at disbursement');

            $table->decimal('outstanding_interest', 20, 4)
                  ->nullable()
                  ->comment('Accrued-but-un-paid interest; set at first accrual');

            $table->decimal('outstanding_penalty', 20, 4)
                  ->default(0)
                  ->comment('Accrued-but-un-paid penalty; begins at zero');

            // -----------------------------------------------------------------
            // Lifecycle dates.
            //
            // `application_date` is NOT NULL and defaults to
            // `CURRENT_TIMESTAMP` at row creation (via `useCurrent()`).
            // The value records the moment the loan application was
            // initiated (draft creation). The submission moment is
            // recorded separately on `loan_status_histories`.
            //
            // The remaining four are nullable and set as the loan
            // progresses through its lifecycle:
            //
            //   decision_date      — at under_review → approved|rejected.
            //   disbursement_date  — at approved → disbursed.
            //   maturity_date      — at disbursement time, set to the
            //                        schedule's final installment's due
            //                        date.
            //   closed_at          — at settled → closed.
            // -----------------------------------------------------------------
            $table->timestamp('application_date')
                  ->useCurrent()
                  ->comment('Moment the loan application was initiated');

            $table->timestamp('decision_date')
                  ->nullable()
                  ->comment('Moment of the approve/reject decision; nullable');

            $table->timestamp('disbursement_date')
                  ->nullable()
                  ->comment('Moment of disbursement; nullable');

            $table->timestamp('maturity_date')
                  ->nullable()
                  ->comment('Scheduled final-installment date; set at disbursement; nullable');

            $table->timestamp('closed_at')
                  ->nullable()
                  ->comment('Moment the loan reached the closed state; nullable');

            // -----------------------------------------------------------------
            // Free-text descriptors.
            // -----------------------------------------------------------------
            $table->string('purpose', 500)
                  ->nullable()
                  ->comment('Free-text purpose supplied by the applicant');

            $table->string('external_reference', 64)
                  ->nullable()
                  ->comment('Optional external reference supplied by the applicant or operator');

            // -----------------------------------------------------------------
            // Actor columns.
            //
            // The user who created the draft, approved the loan, or
            // disbursed it. All three are nullable and use
            // `nullOnDelete()`: a hard-deleted user detaches from the
            // loan, which survives with a null actor.
            //
            // Other lifecycle actors (the rejector, the restructurer,
            // the write-off actor, and so on) are recorded on the
            // corresponding `loan_status_histories` row, not here.
            // -----------------------------------------------------------------
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who approved the loan; nullable');

            $table->foreignId('disbursed_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who disbursed the loan; nullable');

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who created the draft; nullable');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // The table carries `created_at` and `updated_at`. Unlike the
            // append-only ledger tables, the loans row is mutable: its
            // `status`, projections, and lifecycle dates change as the
            // loan progresses. The timestamps record the last mutation.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Composite indexes.
            //
            // Every composite's leading column is `tenant_id`, so the
            // tenant FK's implicit index is satisfied by the first
            // composite. No separate single-column index on `tenant_id`
            // is declared.
            // -----------------------------------------------------------------

            // "List loans by state in tenant" — the index endpoint and
            // every status-filtered report.
            $table->index(
                ['tenant_id', 'status'],
                'loans_tenant_status_index',
            );

            // "List a borrower's loans" — the shareholder portal and the
            // `loan.view_own` scope resolution.
            $table->index(
                ['tenant_id', 'borrower_shareholder_id'],
                'loans_tenant_borrower_index',
            );

            // "List loans by product" — administrative reports.
            $table->index(
                ['tenant_id', 'loan_product_id'],
                'loans_tenant_product_index',
            );

            // "List loans by upcoming maturity" — operational dashboard.
            $table->index(
                ['tenant_id', 'maturity_date'],
                'loans_tenant_maturity_index',
            );

            // "List loans by application order" — the default sort of the
            // index endpoint.
            $table->index(
                ['tenant_id', 'application_date'],
                'loans_tenant_application_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `loan_installments` (A.10),
     * `loan_collaterals` (A.11), `loan_restructurings` (A.12),
     * `loan_write_offs` (A.13), `loan_status_histories` (A.14),
     * `loan_interest_accruals` (A.15), and `loan_penalty_accruals` (A.16)
     * all reference this table via foreign keys, a rollback will only
     * succeed if those tables have been dropped first — which is the
     * normal reverse order of `php artisan migrate:rollback`.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};