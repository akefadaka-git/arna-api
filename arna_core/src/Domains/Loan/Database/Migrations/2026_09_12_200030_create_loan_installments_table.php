<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_installments` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per scheduled installment of a loan. Together, the rows for a
 * given `loan_id` form the loan's contractual amortization schedule: the
 * ordered list of due dates on which the borrower is expected to pay a
 * specific principal-and-interest amount.
 *
 * The schedule is generated ONCE, at approval time, by
 * `LoanScheduleService` (A.23) using `AmortizationCalculator` (A.6). It is
 * the LOAN domain's authoritative record of the contractual schedule — the
 * "what should happen" side of the reconciliation (per B2). The Wallet
 * domain's obligations are the "what financially happened" side.
 *
 * Rows are NEVER deleted. When a loan is restructured, the prior schedule's
 * installments are transitioned to the `superseded` status (per A.5's
 * `InstallmentStatus::Superseded`) and a NEW set of rows is generated for
 * the new schedule. The old rows remain in the table, with their
 * `settled_principal`, `settled_interest`, and `settled_amount` preserved
 * as they stood at the moment of the restructure, providing a complete
 * audit trail of how far the borrower got on the previous schedule.
 *
 * =============================================================================
 * The schedule invariant
 * =============================================================================
 * For every loan, across all its installments (both current and superseded):
 *
 *     Σ(expected_principal)  ==  loans.principal_amount
 *     Σ(expected_interest)   ==  loans.total_scheduled_interest
 *     Σ(expected_total)      ==  loans.total_scheduled_repayment
 *
 * The first equality is guaranteed by `AmortizationCalculator`'s
 * final-installment residual rule (A.6, per D135): the final installment's
 * `expected_principal` is exactly the outstanding balance at that point, so
 * the sum is exact. The second and third equalities hold because the loan
 * row's summary columns are computed from the same schedule.
 *
 * These invariants apply to the CURRENT schedule. When a restructure
 * generates a new schedule, the prior schedule's installments become
 * `superseded`, and the loan row's summary columns are updated to reflect
 * the new schedule. The sum across ALL installments of the loan (current
 * and superseded) therefore no longer equals the loan's current principal —
 * by design. Callers that need the schedule invariant to hold MUST filter
 * to the current schedule (see §"Filtering the current schedule" below).
 *
 * =============================================================================
 * Filtering the current schedule
 * =============================================================================
 * Two filter forms identify the current schedule's installments:
 *
 *     WHERE loan_id = :id AND status != 'superseded'
 *         — includes pending, partial, settled, overdue. This is the set
 *           that can still be paid or transitioned.
 *
 *     WHERE loan_id = :id AND status IN ('pending', 'partial', 'overdue')
 *         — the "still outstanding" set. This is the set that
 *           `LoanReconciliationService` (A.32) sums against the loan's
 *           projections.
 *
 * The `InstallmentStatus::activeValues()` helper (A.5) returns exactly the
 * second set. Callers SHOULD use it rather than hard-coding the strings.
 *
 * =============================================================================
 * Two triples: expected vs. settled
 * =============================================================================
 * Every installment carries six monetary columns, organized as two triples:
 *
 *   Expected triple (the schedule's plan):
 *       expected_principal   — the principal portion due at this
 *                              installment.
 *       expected_interest    — the interest portion due at this
 *                              installment.
 *       expected_total       — the sum of the two; equal to
 *                              expected_principal + expected_interest.
 *
 *   Settled triple (how much has actually been paid):
 *       settled_principal    — the portion of expected_principal that has
 *                              been paid.
 *       settled_interest     — the portion of expected_interest that has
 *                              been paid.
 *       settled_amount       — the sum of the two; equal to
 *                              settled_principal + settled_interest.
 *
 * The two triples allow the reconciliation service (A.32) and any report to
 * distinguish "how much was expected" from "how much was paid", at both the
 * total and the principal/interest split.
 *
 * The `settled_*` columns begin at `0.0000` when the row is created and are
 * incremented by `LoanRepaymentService` (A.27) inside the same transaction
 * that records the corresponding Wallet payment and allocation. The service
 * decides which installment receives the payment, per the loan's allocation
 * policy — the split between principal and interest is computed by the
 * service and written into `settled_principal` and `settled_interest`
 * separately.
 *
 * The invariant `settled_amount = settled_principal + settled_interest` is
 * maintained by the service on every update; the schema does not enforce it
 * (the check is a service-layer concern, consistent with the Phase 2A and
 * Phase 2B precedents for cross-column monetary invariants).
 *
 * =============================================================================
 * Status
 * =============================================================================
 * `status` stores the `InstallmentStatus` enum value (A.5):
 *
 *     pending      — no settlement yet.
 *     partial      — some settlement, less than expected_total.
 *     settled      — fully settled. Terminal.
 *     overdue      — past due_date + grace without full settlement.
 *     superseded   — part of a schedule replaced by a restructure.
 *                    Terminal.
 *
 * The default is `'pending'`, the state every installment is created in.
 * The column is VARCHAR(16) — the longest current value is `superseded`
 * (10 characters) — not a MySQL ENUM, matching the platform-wide convention.
 *
 * Status transitions are performed by:
 *
 *     pending → partial | settled | overdue | superseded
 *         by LoanRepaymentService (A.27, partial/settled),
 *            LoanArrearsService (A.29, overdue),
 *            LoanRestructuringService (A.30, superseded).
 *
 *     partial → settled | overdue | superseded
 *         by the same three services.
 *
 *     overdue → settled | superseded
 *         by LoanRepaymentService (A.27, settled — an overdue installment
 *         can be brought current by a late payment), or by
 *         LoanRestructuringService (A.30, superseded).
 *
 *     settled | superseded → (none)
 *         terminal states.
 *
 * The structural matrix is enforced by `InstallmentStatus::canTransitionTo()`
 * (A.5). The services compose that check with guards and event dispatch.
 *
 * =============================================================================
 * Due date
 * =============================================================================
 * `due_date` is a DATE column (not a timestamp). The schedule's granularity
 * is the calendar day; a due date of `2026-10-15` means the installment is
 * due at any point on that day, and the grace period (per D133) begins the
 * following day.
 *
 * The schedule's dates are computed by `LoanScheduleService` (A.23) by
 * advancing the disbursement date by the installment's month offset. The
 * disbursement date itself is a TIMESTAMP (the moment the loan was
 * disbursed); the day-of-month is extracted and used to derive each
 * installment's due date. If a month has fewer days than the disbursement
 * day-of-month (e.g. a disbursement on the 31st), the schedule logic clamps
 * to the last day of the target month — that clamping rule is a
 * `LoanScheduleService` concern and is documented in that service.
 *
 * The Ethiopian calendar is NOT used for the schedule's storage. Dates are
 * stored in the Gregorian calendar, as the platform's `application_date`
 * and `maturity_date` columns on `loans` (A.9) are. If a future Change
 * Request requires Ethiopian-calendar display of due dates, that is a
 * presentation-layer concern; the storage format does not change.
 *
 * =============================================================================
 * Installment number
 * =============================================================================
 * `installment_number` is a 1-based integer that identifies the
 * installment's position in its schedule. The first installment is number
 * 1; the last is number N (where N is the loan's term in months). The
 * sequence is assigned by `AmortizationCalculator` (A.6) and persisted
 * unchanged.
 *
 * The uniqueness constraint `UNIQUE (loan_id, installment_number)` ensures
 * that a given loan has at most one installment at each position in its
 * schedule.
 *
 * When a restructure supersedes a schedule, the NEW schedule's installments
 * restart at number 1. This is safe because the composite UNIQUE includes
 * `loan_id` only, not the schedule generation — but it means two rows with
 * the same `(loan_id, installment_number)` can exist if one is `superseded`
 * and the other is current.
 *
 * This is a deliberate trade-off: the alternative — including a
 * schedule-generation identifier in the UNIQUE — would add a column that
 * only has meaning for loans that have been restructured, at the cost of
 * complexity for the common case. The reconciliation service (A.32) and any
 * schedule-display path MUST filter by `status` to disambiguate (see
 * §"Filtering the current schedule").
 *
 * Wait — the UNIQUE constraint is `UNIQUE (loan_id, installment_number)`.
 * If a restructure creates a new schedule with the same numbers, the
 * constraint will be violated.
 *
 * Correction: the UNIQUE constraint is therefore declared as
 * `UNIQUE (loan_id, installment_number) WHERE status != 'superseded'` —
 * a PARTIAL unique index, which MySQL 8 does NOT support in the general
 * case (unlike PostgreSQL). MySQL 8 supports functional indexes but not
 * filtered unique indexes on arbitrary columns.
 *
 * The correct resolution for MySQL 8: declare the UNIQUE on
 * `(loan_id, installment_number)` and have `LoanScheduleService` (A.23)
 * assign a MONOTONICALLY INCREASING `installment_number` across all
 * schedules of a given loan. The first schedule uses numbers 1..N; a
 * restructure's new schedule uses numbers (N+1)..(N+M); a second
 * restructure continues from (N+M+1). The `installment_number` then
 * identifies the row uniquely across the loan's lifetime, and the
 * in-schedule position is derived by the ordinal of the row's number
 * relative to the schedule generation's start.
 *
 * The cost: an installment's `installment_number` no longer equals its
 * position within its own schedule (an installment that is the "3rd of the
 * restructured schedule" might carry `installment_number = 15` if the
 * prior schedule had 12 installments). The benefit: the UNIQUE constraint
 * is enforceable, the numbering is stable across the loan's lifetime, and
 * the reconciliation and audit paths can address installments by a single
 * identifier.
 *
 * See `LoanScheduleService` (A.23) for the numbering algorithm. The
 * migration declares the constraint; the service implements the numbering.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * One foreign key:
 *
 *     loan_id  →  loans.id  RESTRICT
 *
 * Q6 and the Phase 2B precedent mandate RESTRICT for loan-owned financial
 * FKs. A hard delete of a loan must not cascade into its installments; the
 * loan itself is never hard-deleted through any code path, but the RESTRICT
 * makes an administrative delete fail loudly.
 *
 * The FK is declared with `cascadeOnUpdate()` for consistency with the
 * platform-wide conventions (updates to the referenced primary key are not
 * a runtime operation in Phase 2C; the cascade reflects the standard
 * posture).
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two composite indexes support the Phase 2C query patterns:
 *
 *   (loan_id, due_date)  — supports "list a loan's installments in due-date
 *                          order", the primary read pattern for schedule
 *                          display, for the reconciliation service's
 *                          per-installment breakdown, and for any
 *                          chronological iteration of the schedule.
 *
 *   (loan_id, status)    — supports "list a loan's installments filtered by
 *                          state", used by the arrears-detection command
 *                          (A.88, filters to `pending` and `partial`), by
 *                          the reconciliation service (A.32, filters to
 *                          non-superseded installments), and by any
 *                          report that segments the schedule by state.
 *
 * The UNIQUE constraint on `(loan_id, installment_number)` provides the
 * FK's implicit index on `loan_id` — no separate single-column index on
 * `loan_id` is declared.
 *
 * No index on `due_date` alone. Every 2C query that filters by due date is
 * per-loan (the arrears-detection command iterates loans first, then
 * examines each loan's installments); the composite `(loan_id, due_date)`
 * serves those queries efficiently.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The `settled_amount = settled_principal + settled_interest` invariant.
 *     Enforced by `LoanRepaymentService` (A.27), which always writes the
 *     three columns together.
 *
 *   - The `expected_total = expected_principal + expected_interest`
 *     invariant. Guaranteed by construction in
 *     `AmortizationCalculator` (A.6); the schema does not re-verify it.
 *
 *   - The state transition matrix. Enforced by
 *     `InstallmentStatus::canTransitionTo()` (A.5) and by the services
 *     that drive the transitions.
 *
 *   - The relationship between an installment's status and its settled
 *     amounts. For example, an installment with `status = 'settled'` must
 *     have `settled_amount = expected_total`; the schema does not express
 *     this cross-column implication. The service maintains it, and the
 *     reconciliation service (A.32) detects any violation as part of its
 *     periodic verification.
 *
 *   - The relationship between the installment's `due_date` and the
 *     loan's `maturity_date`. The final installment's due date equals the
 *     loan's maturity date by construction of the schedule.
 *
 *   - The monotonic `installment_number` assignment across schedules of
 *     the same loan. Enforced by `LoanScheduleService` (A.23); the schema
 *     enforces only that the number is unique within a loan.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D133, D135,
 *                                                  B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C Final Reconciliation, 2026-09-12    — reconciliation invariant
 *                                                  (loan-linked obligations)
 *   Step 5 (manifest) A.10                       — This file
 *   Step 6 v1.1.0 §3.10                          — loan_installments table
 *                                                  specification
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_installments` table. The sole foreign key targets
     * the `loans` table (migration A.9), which must already exist when
     * this migration runs. Migration ordering: A.9 (prefix `200020`) runs
     * before this file (prefix `200030`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // Every installment belongs to exactly one loan. The composite
            // UNIQUE below uses `loan_id` as its leading column, so MySQL
            // reuses it for the FK's implicit index — no separate
            // single-column index on `loan_id` is declared.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its installments (Q6, Architecture §17.3).
            // -----------------------------------------------------------------
            $table->foreignId('loan_id')
                  ->constrained('loans')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning loan');

            // -----------------------------------------------------------------
            // Installment number.
            //
            // A 1-based integer that identifies the installment's position
            // in the loan's schedule. The numbering is MONOTONIC across
            // the loan's lifetime: a first schedule uses 1..N; a
            // restructure's new schedule continues from N+1; a second
            // restructure continues from (N+M)+1.
            //
            // See the class docblock §"Installment number" for the
            // rationale. The UNIQUE constraint on (loan_id,
            // installment_number) enforces the uniqueness that the
            // monotone numbering guarantees.
            // -----------------------------------------------------------------
            $table->integer('installment_number')
                  ->comment('1-based position in the schedule; monotonic across schedules of the same loan');

            // -----------------------------------------------------------------
            // Due date.
            //
            // The calendar day on which this installment becomes due. A
            // DATE (not a timestamp): the schedule's granularity is the
            // day. The grace period (per D133) begins the following day.
            //
            // Set by `LoanScheduleService` (A.23) from the disbursement
            // date and the installment's month offset. Immutable once
            // written; a restructure supersedes the row rather than
            // updating its due date.
            // -----------------------------------------------------------------
            $table->date('due_date')
                  ->comment('Calendar day the installment becomes due');

            // -----------------------------------------------------------------
            // Expected triple.
            //
            // The schedule's plan for this installment: how much principal
            // and interest it carries, and their sum. All three are
            // DECIMAL(20,4), matching the platform-wide monetary precision.
            //
            // Immutable once written. A restructure supersedes the row
            // rather than modifying its expected amounts.
            //
            // The `expected_total = expected_principal + expected_interest`
            // invariant is guaranteed by construction in
            // `AmortizationCalculator` (A.6); the schema does not
            // re-verify it.
            // -----------------------------------------------------------------
            $table->decimal('expected_principal', 20, 4)
                  ->comment('Principal portion due at this installment');

            $table->decimal('expected_interest', 20, 4)
                  ->comment('Interest portion due at this installment');

            $table->decimal('expected_total', 20, 4)
                  ->comment('Sum of expected_principal and expected_interest');

            // -----------------------------------------------------------------
            // Settled triple.
            //
            // How much has actually been paid against this installment.
            // All three default to `0.0000` and are incremented by
            // `LoanRepaymentService` (A.27) inside the transaction that
            // records the corresponding Wallet payment.
            //
            // The `settled_amount = settled_principal + settled_interest`
            // invariant is maintained by the service; the schema does not
            // enforce it.
            //
            // For a superseded installment, the settled triple is frozen
            // at its value at the moment of the restructure — it is not
            // reset to zero. This preserves the audit trail of how much
            // the borrower paid on the previous schedule.
            // -----------------------------------------------------------------
            $table->decimal('settled_principal', 20, 4)
                  ->default(0)
                  ->comment('Portion of expected_principal that has been paid');

            $table->decimal('settled_interest', 20, 4)
                  ->default(0)
                  ->comment('Portion of expected_interest that has been paid');

            $table->decimal('settled_amount', 20, 4)
                  ->default(0)
                  ->comment('Sum of settled_principal and settled_interest');

            // -----------------------------------------------------------------
            // Status.
            //
            // Stores an `InstallmentStatus` enum value (A.5):
            //
            //     pending | partial | settled | overdue | superseded
            //
            // The default is `'pending'`, the state every installment is
            // created in.
            //
            // VARCHAR(16) accommodates every current value with headroom
            // (the longest is `superseded`, 10 characters). The column is
            // not a MySQL ENUM; adding a state updates the enum, not the
            // schema.
            // -----------------------------------------------------------------
            $table->string('status', 16)
                  ->default('pending')
                  ->comment('InstallmentStatus enum value; current settlement state');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // The table carries `created_at` and `updated_at`. Unlike the
            // append-only ledger tables, an installment row is legitimately
            // mutated: its `settled_*` columns and `status` change as
            // payments are received, and its `status` transitions to
            // `overdue` when arrears is detected, or to `superseded` when
            // a restructure occurs. The timestamps record the last
            // mutation.
            //
            // The `due_date` and the `expected_*` columns are immutable
            // after row creation, but they coexist with the mutable
            // settlement columns in the same row; the append-only
            // discipline applies to the two immutable groups, not to the
            // row as a whole.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Composite uniqueness — one installment per (loan, number).
            //
            // Enforces the numbering invariant: a loan has at most one
            // installment at any given `installment_number`. The monotonic
            // numbering across schedules (see the class docblock) ensures
            // that a restructured loan does not collide with its prior
            // schedule's numbers.
            //
            // The composite index's leading column (`loan_id`) also serves
            // as the backing index for the `loan_id` FK, so MySQL does not
            // create a duplicate single-column index for that FK.
            // -----------------------------------------------------------------
            $table->unique(
                ['loan_id', 'installment_number'],
                'loan_installments_loan_number_unique',
            );

            // -----------------------------------------------------------------
            // Composite indexes.
            // -----------------------------------------------------------------

            // "List a loan's installments in due-date order" — schedule
            // display, per-installment reconciliation, chronological
            // iteration.
            $table->index(
                ['loan_id', 'due_date'],
                'loan_installments_loan_due_index',
            );

            // "List a loan's installments by state" — arrears detection
            // (filters to pending/partial), reconciliation (filters out
            // superseded), and state-segmented reports.
            $table->index(
                ['loan_id', 'status'],
                'loan_installments_loan_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `loan_interest_accruals` (A.15) and
     * `loan_penalty_accruals` (A.16) reference this table via foreign
     * keys, a rollback will only succeed if those tables have been
     * dropped first — which is the normal reverse order of
     * `php artisan migrate:rollback`.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};