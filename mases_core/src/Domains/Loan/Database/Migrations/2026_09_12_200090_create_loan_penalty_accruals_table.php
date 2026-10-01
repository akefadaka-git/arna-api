<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_penalty_accruals` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per penalty recognized against an overdue loan installment. The
 * table is the Loan domain's bookkeeping for the penalty obligations it
 * has recorded on the Wallet: each row links a specific installment to the
 * Wallet obligation that was created when the installment crossed its
 * grace threshold without full settlement.
 *
 * The table does NOT carry the monetary ledger — that belongs to the
 * Wallet domain (Phase 2B, `wallet_obligations` and `wallet_entries`). It
 * carries the LOAN-side record of what has already been penalized, so that
 * the scheduled arrears-detection command can be run repeatedly without
 * creating duplicate Wallet obligations.
 *
 * The table is a structural sibling of `loan_interest_accruals` (A.15).
 * Same shape, same idempotency role, same append-only discipline. The two
 * tables differ only in the semantics of the amount they record (penalty
 * vs. interest) and in the trigger that produces a row (grace-threshold
 * crossing vs. due-date arrival).
 *
 * =============================================================================
 * The D133 freeze that governs this table
 * =============================================================================
 * Decision D133 (Phase 2C Decision Register, 2026-09-12) freezes the
 * following rules for arrears handling:
 *
 *     Form:           penalty / late-charge obligation.
 *     Not:            ordinary contractual interest.
 *     Basis:          the overdue installment amount.
 *     Start:          after the approved grace period.
 *     Compounding:    none.
 *     Capitalization: prohibited.
 *     Rounding:       same as A9 (half-up at scale 4).
 *     Max penalty:    product/policy controlled.
 *     Restructuring:  future arrears treatment is explicitly recalculated
 *                     according to the approved restructuring rule.
 *     Write-off:      does not silently erase the historical arrears ledger.
 *     Auditability:   every arrears charge is independently auditable.
 *
 * The table is the schema-level embodiment of the "independently auditable"
 * clause. Each row records: which installment was penalized, when, the
 * amount, the resulting Wallet obligation, and (via the link back to the
 * installment) the basis for the penalty. A reviewer can reconstruct the
 * full penalty history of a loan from this table alone, without reading the
 * Wallet.
 *
 * The "no compounding, no capitalization" clauses are structural: this
 * table records ONE penalty per overdue installment (enforced by the
 * UNIQUE constraint below). A penalty does not accumulate across days; it
 * is recognized once when the installment first crosses its grace
 * threshold, and it does not roll into any subsequent computation base.
 * A future Change Request that introduces daily compounding would require
 * a different key format (see §"The accrual key format" below) and would
 * be a substantive change to the D133 freeze.
 *
 * =============================================================================
 * The idempotency role (Phase 2C Final Reconciliation §4.2, §4.3)
 * =============================================================================
 * The table's central purpose is idempotency. The
 * `DetectArrearsCommand` (A.88) runs daily on the Plesk scheduler. It
 * examines every loan in an arrears-eligible state and, for each
 * installment whose grace threshold has been crossed without full
 * settlement, records a penalty obligation on the borrower's Wallet.
 *
 * Without this table, a duplicate run of the command would create a
 * SECOND penalty obligation for the same installment. The borrower would
 * be penalized twice for the same arrears. That is a legal-exposure
 * defect, not merely a correctness one.
 *
 * The table's `UNIQUE (accrual_period_key)` constraint prevents this at
 * the database level, using the same transactional pattern as A.15:
 *
 *   1. The command builds a deterministic key from the loan and the
 *      installment it is about to penalize:
 *
 *          accrual_period_key = "{loan_id}:{installment_id}"
 *
 *   2. The command attempts to INSERT a row with that key inside a
 *      transaction. If the key already exists, the INSERT fails with a
 *      unique-constraint violation, and the command catches the failure
 *      and skips the installment.
 *
 *   3. If the key does not already exist, the INSERT succeeds, the
 *      command proceeds to call `ObligationService::record()` on the
 *      Wallet with component `ObligationComponent::Penalty`, and stores
 *      the resulting Wallet obligation's id on the new row
 *      (`wallet_obligation_id`).
 *
 *   4. The whole sequence runs inside one transaction. If the Wallet
 *      call fails after the accrual row was inserted, the transaction
 *      rolls back, and the next command run retries the installment from
 *      a clean state.
 *
 * The "no compounding" and "no capitalization" clauses of D133 are
 * therefore enforced by the SCHEMA (through the unique key), not merely by
 * the command's logic. A defect in the command cannot produce a duplicate
 * penalty for the same installment.
 *
 * =============================================================================
 * The accrual key format, and the non-daily-penalty freeze
 * =============================================================================
 * The key format `"{loan_id}:{installment_id}"` reflects the FROZEN
 * semantics of the current penalty scheme:
 *
 *     ONE penalty obligation per overdue installment, recognized when
 *     the installment first crosses its grace threshold. Not daily
 *     penalty accrual. Not periodic compounding. One penalty per
 *     installment, ever.
 *
 * The command name `DetectArrearsCommand` could be misread as a daily
 * penalty accumulator, and the fact that the command itself runs daily on
 * the scheduler reinforces that misreading. The command's class docblock
 * (A.88) and this migration's docblock together record the freeze
 * explicitly:
 *
 *     Phase 2C does NOT implement daily penalty accrual. It
 *     recognizes one penalty obligation per overdue installment, at
 *     the moment the installment crosses its grace threshold.
 *
 * If a future Change Request introduces a compounding or escalating
 * penalty scheme (for example, "penalty grows by X% per day in arrears"),
 * that CR must:
 *
 *   1. Extend the key format to include an accrual date component:
 *
 *          accrual_period_key = "{loan_id}:{installment_id}:{YYYY-MM-DD}"
 *
 *   2. Add a column to record the accrual date (or extract it from the
 *      key) so that the daily series is queryable.
 *
 *   3. Update the `DetectArrearsCommand` to iterate by date range rather
 *      than by "installments whose grace has just expired".
 *
 *   4. Reconcile the change with the D133 freeze, which currently
 *      prohibits compounding and capitalization. Such a CR would be a
 *      substantive change to the freeze, not a schema-only update.
 *
 * The VARCHAR(64) width of `accrual_period_key` accommodates the current
 * format (at most 41 characters) and a future date-suffixed format (at
 * most 52 characters) without a schema change.
 *
 * =============================================================================
 * Trigger and grace period
 * =============================================================================
 * A penalty row is created when an installment's grace threshold is
 * crossed without full settlement. The threshold is:
 *
 *     due_date  +  product_policy.arrears.grace_days
 *
 * where `product_policy.arrears.grace_days` is read from the loan's frozen
 * `loan_product_rules` policy version (B6, Extra 2; see
 * `LoanPolicyKeys::ARREARS_GRACE_DAYS`, A.7). A value of `0` means no
 * grace: the installment is penalized the day after its due date.
 *
 * The command compares each installment's `due_date` to "today" and, if
 * the grace has elapsed and the installment is not settled, transitions
 * the installment to `InstallmentStatus::Overdue` (A.5) and records the
 * penalty row. The penalty amount is computed as:
 *
 *     penalty = halfUp(overdue_amount · penalty_rate, 4)
 *
 * where `overdue_amount` is the installment's unpaid balance
 * (`expected_total − settled_amount`) and `penalty_rate` is read from
 * `product_policy.arrears.penalty_rate` (see
 * `LoanPolicyKeys::ARREARS_PENALTY_RATE`, A.7). If
 * `product_policy.arrears.cap` is non-null, the computed penalty is
 * capped at that value before being recorded.
 *
 * The exact amount computation is the responsibility of
 * `LoanArrearsService` (A.29); this migration only records the result.
 *
 * =============================================================================
 * Why a dedicated table and not a column on `loan_installments`
 * =============================================================================
 * The reasoning is identical to the sibling table's (A.15
 * §"Why a dedicated table"): audit completeness, schema symmetry with the
 * interest-accrual table, forward-compatibility with any future change in
 * the accrual cadence, and separation of the contractual schedule
 * (`loan_installments`) from the operational fact of a Wallet obligation
 * that was created.
 *
 * =============================================================================
 * The `wallet_obligation_id` reference
 * =============================================================================
 * When the command succeeds in inserting the penalty row, it calls
 * `ObligationService::record()` on the Wallet with component
 * `ObligationComponent::Penalty`. The Wallet returns the created
 * obligation, and the command updates this row's `wallet_obligation_id`
 * to that obligation's id.
 *
 * The column is NULLABLE for the same reason as the sibling table's:
 * the row must be insertable BEFORE the Wallet obligation's id is known,
 * and the two operations run inside one transaction:
 *
 *   BEGIN TRANSACTION
 *     INSERT INTO loan_penalty_accruals (..., wallet_obligation_id = NULL)
 *       → obtains penalty row id; UNIQUE constraint on accrual_period_key
 *         either admits the insert or rejects a duplicate
 *     CALL ObligationService::record() on the Wallet with
 *          component = Penalty
 *       → obtains wallet obligation id
 *     UPDATE loan_penalty_accruals SET wallet_obligation_id = <id>
 *     IF this is the first overdue installment for the loan:
 *       UPDATE loans SET status = 'in_arrears'
 *       DISPATCH LoanEnteredArrears after commit
 *     DISPATCH LoanPenaltyRecorded after commit
 *   COMMIT
 *
 * If the Wallet call fails, the transaction rolls back. On a successful
 * run, the row is committed with a non-null `wallet_obligation_id`. The
 * nullable state is therefore transient; a reader of the committed table
 * sees only rows with non-null values.
 *
 * The FK on this column uses `restrictOnDelete()`, matching A.15.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * A row of this table is immutable once committed, with the same
 * transactional nuance as A.15:
 *
 *   - The `wallet_obligation_id` UPDATE happens INSIDE the row's own
 *     insert transaction, and is invisible to interleaved readers.
 *
 *   - The `LoanPenaltyAccrual` model (A.19) sets `$timestamps = false`
 *     and exposes no mutating methods beyond the initial creation and the
 *     one-time `wallet_obligation_id` write.
 *
 *   - There is NO `updated_at` column. The table is treated as
 *     append-only at the reader level, matching the platform convention.
 *
 *   - The `created_at` column is written explicitly by the command and is
 *     nullable, matching the platform convention for append-only tables.
 *
 * A future Change Request that introduces a correction to a penalty (for
 * example, correcting a mis-applied penalty after a disputed arrearage)
 * would reverse the Wallet obligation through the Wallet's reversal
 * mechanism and record a correction row (or a reversal record) on this
 * table. The existing row is never mutated.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Three foreign keys:
 *
 *     loan_id              → loans.id               RESTRICT
 *     installment_id       → loan_installments.id   RESTRICT
 *     wallet_obligation_id → wallet_obligations.id  RESTRICT (nullable)
 *
 * The FK semantics mirror A.15 exactly. See the sibling table's docblock
 * for the full rationale on each FK's delete behavior and on the
 * cross-domain Loan → Wallet FK.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two indexes support the Phase 2C query patterns:
 *
 *   UNIQUE (accrual_period_key)  — the idempotency constraint; also
 *                                  serves as the primary lookup for
 *                                  "has this installment already been
 *                                  penalized?".
 *
 *   (loan_id, recorded_at)       — "list a loan's penalty accruals in
 *                                  chronological order", the read
 *                                  pattern used by the reconciliation
 *                                  service (A.32) and by the loan's
 *                                  detail view. The composite's leading
 *                                  column also serves as the FK-supporting
 *                                  index on `loan_id`.
 *
 * The `installment_id` and `wallet_obligation_id` FKs receive MySQL's
 * implicit single-column indexes automatically; no explicit index is
 * declared.
 *
 * No index on `penalty_amount` or on `recorded_at` alone. No Phase 2C
 * query filters or sorts by either in isolation.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The "one penalty per installment" rule beyond the UNIQUE constraint
 *     on `accrual_period_key`. The key's determinism from `(loan_id,
 *     installment_id)` makes the UNIQUE exactly the one-penalty rule.
 *
 *   - The positivity of `penalty_amount`. Enforced by
 *     `LoanArrearsService` (A.29) at write time.
 *
 *   - The consistency between `penalty_amount` and the current
 *     `overdue_amount`. The penalty is computed ONCE, at the moment the
 *     installment crosses its grace threshold, against the overdue
 *     balance at that moment. A subsequent partial payment reduces the
 *     installment's `settled_amount` gap but does NOT reduce the penalty
 *     — the penalty is a discrete fact, not a continuous accrual. A
 *     future Change Request that introduces penalty recalculation on
 *     partial payment would be a substantive change to D133's freeze.
 *
 *   - The presence of `wallet_obligation_id` on committed rows. As
 *     documented above, the column is nullable because the two-step
 *     write happens inside one transaction; a committed row always has
 *     a non-null value in practice.
 *
 *   - The re-penalization of an installment after a restructure. When a
 *     loan is restructured, the previous schedule's installments are
 *     superseded (A.10) and their corresponding Wallet obligations are
 *     discharged (A.12). Any penalties already recognized against those
 *     installments remain recorded on this table — the historical fact
 *     of the arrearage is preserved (per D133's "restructuring
 *     recalculates future arrears treatment" clause, which does not
 *     retroactively erase past penalties). New installments from the new
 *     schedule will accrue penalties on their own grace-threshold
 *     crossings, generating new accrual rows.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D133 (arrears rules),
 *                                                  B3 (interest recording
 *                                                  pattern; the sibling
 *                                                  pattern that penalties
 *                                                  follow)
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation
 *                                                  combination)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2 (this table's
 *                                                  specification),
 *                                                  §4.3 (command semantics),
 *                                                  §4.4 (policy-data-driven
 *                                                  amounts)
 *   Phase 2B migration 2B.50                     — `wallet_obligations`
 *                                                  (FK target)
 *   Phase 2A migration 2A.40                     — `share_holding_entries`
 *                                                  (append-only convention)
 *   Phase 2B migration 2B.54                     — `wallet_entries`
 *                                                  (append-only convention)
 *   Step 5 (manifest) A.16                       — This file
 *   Step 6 v1.1.0 §3.16                          — loan_penalty_accruals
 *                                                  table specification
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_penalty_accruals` table. Foreign keys target the
     * `loans` table (A.9), the `loan_installments` table (A.10), and the
     * Phase 2B `wallet_obligations` table (2B.50) — all of which must
     * already exist when this migration runs.
     *
     * Migration ordering: A.9 (prefix `200020`) and A.10 (prefix `200030`)
     * both run before this file (prefix `200090`). Phase 2B migration
     * 2B.50 (prefix `100020`) also runs before this file.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_penalty_accruals', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // The loan this penalty belongs to. Redundant with the
            // `installment_id`'s transitive link, but stored explicitly so
            // that reconciliation and reporting queries can filter by loan
            // without joining through `loan_installments`.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its penalty history (Q6).
            //
            // The composite index (loan_id, recorded_at) declared below
            // provides the FK's implicit index on `loan_id` — no separate
            // single-column index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('loan_id')
                  ->constrained('loans')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning loan');

            // -----------------------------------------------------------------
            // Penalized installment.
            //
            // The installment whose grace-threshold crossing triggered
            // this penalty. On delete: restrict. An installment is never
            // hard-deleted through any code path (a restructure
            // supersedes it rather than deleting it; see A.10), and a
            // penalty row must not be orphaned by a hypothetical
            // administrative delete.
            //
            // The FK's implicit single-column index is provided
            // automatically by MySQL — no explicit index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('installment_id')
                  ->constrained('loan_installments')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Installment whose grace threshold triggered this penalty');

            // -----------------------------------------------------------------
            // Accrual period key.
            //
            // The deterministic idempotency key for this penalty, in the
            // format:
            //
            //     "{loan_id}:{installment_id}"
            //
            // The key is built by `LoanArrearsService` (A.29) and stored
            // as-is. Its uniqueness (enforced below) is what makes the
            // daily `DetectArrearsCommand` (A.88) idempotent across
            // duplicate runs. See the class docblock §"The idempotency
            // role" and §"The accrual key format" for the full rationale.
            //
            // VARCHAR(64) accommodates the current format and a future
            // date-suffixed format (for a compounding scheme) without a
            // schema change.
            // -----------------------------------------------------------------
            $table->string('accrual_period_key', 64)
                  ->comment('Deterministic key "{loan_id}:{installment_id}"; the idempotency constraint');

            // -----------------------------------------------------------------
            // Penalty amount.
            //
            // The penalty amount recognized for this installment,
            // computed by `LoanArrearsService` (A.29) from the loan's
            // frozen `loan_product_rules` policy (per D133). The value is
            // passed verbatim to `ObligationService::record()` on the
            // Wallet with component `ObligationComponent::Penalty`.
            //
            // NOT NULL: the amount is always known at the moment the row
            // is inserted; a null would indicate a service defect.
            //
            // DECIMAL(20,4) matches every monetary column on the platform.
            // -----------------------------------------------------------------
            $table->decimal('penalty_amount', 20, 4)
                  ->comment('Penalty amount recognized for this installment');

            // -----------------------------------------------------------------
            // Wallet obligation reference.
            //
            // The `wallet_obligations.id` of the obligation that this
            // penalty produced. Nullable for the same transactional reason
            // as the sibling interest-accrual table's column; see the
            // class docblock §"The `wallet_obligation_id` reference".
            //
            // On delete: restrict. Wallet obligations are financial facts
            // and must not be silently removed while this row references
            // them.
            //
            // The FK's implicit single-column index is provided
            // automatically by MySQL — no explicit index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('wallet_obligation_id')
                  ->nullable()
                  ->constrained('wallet_obligations')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Wallet obligation produced by this penalty; written back inside the transaction');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The moment the penalty was recognized. In the standard
            // penalty path, `recorded_at` is the moment the command ran
            // and detected the grace-threshold crossing — which is the
            // day after the installment's `due_date + grace_days`.
            //
            // `useCurrent()` provides a sensible default at insert time;
            // the service supplies the value explicitly. The column type
            // is TIMESTAMP (not DATE) so that a future CR that introduces
            // intra-day penalty accrual has a stable shape.
            // -----------------------------------------------------------------
            $table->timestamp('recorded_at')
                  ->useCurrent()
                  ->comment('Moment the penalty was recognized');

            // -----------------------------------------------------------------
            // Insert time.
            //
            // Nullable, matching the platform's append-only-table
            // convention. There is NO `updated_at` column; see the class
            // docblock §"Immutability".
            //
            // The `LoanPenaltyAccrual` model (A.19) sets
            // `$timestamps = false` accordingly.
            // -----------------------------------------------------------------
            $table->timestamp('created_at')
                  ->nullable()
                  ->comment('Row insert timestamp; written explicitly, no updated_at');

            // -----------------------------------------------------------------
            // Composite indexes and the idempotency constraint.
            // -----------------------------------------------------------------

            // The idempotency constraint. Because the key is deterministic
            // from (loan_id, installment_id), this UNIQUE is exactly the
            // one-penalty-per-installment rule. It also serves as the
            // primary lookup for "has this installment already been
            // penalized?".
            $table->unique(
                'accrual_period_key',
                'loan_penalty_accruals_key_unique',
            );

            // "List a loan's penalty accruals in chronological order" —
            // the reconciliation read pattern and the loan detail view.
            // The composite's leading column also satisfies the `loan_id`
            // FK's implicit index requirement.
            $table->index(
                ['loan_id', 'recorded_at'],
                'loan_penalty_accruals_loan_date_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No other Phase 2C migration references this table
     * via a foreign key. A rollback of this migration is therefore
     * independent of any other Phase 2C table's rollback order (beyond
     * the standard reverse-order requirement that `loan_installments`,
     * `loans`, and `wallet_obligations` still exist when this table's
     * FK constraints are dropped).
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_penalty_accruals');
    }
};