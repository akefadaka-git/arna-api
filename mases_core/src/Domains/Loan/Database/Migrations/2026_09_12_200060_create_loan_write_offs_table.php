<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_write_offs` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per write-off event on a loan. A write-off is the lender's
 * decision to forgive the loan's outstanding balance: the loan transitions
 * to the terminal `written_off` state, every outstanding Wallet obligation
 * linked to the loan is discharged, and the loan's projections are zeroed.
 *
 * Each row is an immutable historical record: the loan's state at the
 * moment of write-off, the amount forgiven, the reason, and the actor. The
 * row is NEVER updated and NEVER deleted.
 *
 * A loan can be written off at most once: the `written_off` state is
 * terminal (A.2, per the C4 freeze), and no transition from it exists.
 * There is therefore at most one row per `loan_id` in this table. This is
 * a domain invariant enforced by `LoanStateMachine` (A.26), not by a
 * schema-level UNIQUE constraint — see §"What this table does NOT enforce".
 *
 * =============================================================================
 * Transactional role — the reference-id ordering fix (Phase 2C Final
 * Reconciliation §1.3)
 * =============================================================================
 * This table is the FIRST write in the write-off transaction, before any
 * Wallet discharge occurs. The reason is that the Wallet discharge
 * operations need a stable `reference_id` to identify the write-off that
 * caused them, and that `reference_id` is this table's generated primary
 * key.
 *
 * The frozen write-off transaction is:
 *
 *   1. Validate loan state ∈ {active, in_arrears, restructured};
 *      validate `loan.writeoff` permission; validate reason non-empty.
 *   2. INSERT this row → obtain $writeOffId.  ← THIS MIGRATION'S ROLE
 *   3. For each outstanding Wallet obligation linked to the loan:
 *        ObligationService::discharge(
 *          ...,
 *          referenceType: 'LoanWriteOff',
 *          referenceId:   (string) $writeOffId,
 *        );
 *   4. Set the loan's projections to zero:
 *        loan.outstanding_principal = 0
 *        loan.outstanding_interest  = 0
 *        loan.outstanding_penalty   = 0
 *   5. Transition the loan's status to `written_off`.
 *   6. Register after-commit dispatch of `LoanWrittenOff`.
 *   COMMIT
 *
 * If any step after step 2 fails, the entire transaction rolls back —
 * including the row inserted into this table. Atomicity is preserved; the
 * ordering only ensures that the generated id is available to step 3.
 *
 * The same ordering fix was applied to `loan_restructurings` (A.12); the
 * two tables share the pattern because their transactions share the shape.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * A row of this table is immutable once written:
 *
 *   - No code path updates a row after insert. The `LoanWriteOff` model
 *     (A.20) exposes no mutating methods, and the service that writes rows
 *     (`LoanWriteOffService`, A.31) exposes only `writeOff()`, which
 *     inserts and returns.
 *
 *   - The `updated_at` column IS present (via `timestamps()`), following
 *     the platform convention for append-only financial tables that carry
 *     standard Eloquent timestamps. Mutation is prevented at the SERVICE
 *     layer, not by removing the column. This mirrors
 *     `wallet_payment_allocation_reversals` (Phase 2B, migration 2B.53)
 *     and `share_holding_entries` (Phase 2A, migration 2A.40).
 *
 *   - A future Change Request that introduces a "reverse a write-off"
 *     operation would write a NEW row (or a dedicated reversal record); it
 *     would NOT update this row.
 *
 * =============================================================================
 * The "at write-off" columns and the sum invariant
 * =============================================================================
 * Three columns snapshot the loan's outstanding projections at the moment
 * the write-off row is inserted:
 *
 *     outstanding_principal_at_writeoff
 *     outstanding_interest_at_writeoff
 *     outstanding_penalty_at_writeoff
 *
 * The `write_off_amount` column records the total amount forgiven. Under
 * the frozen flow, the three outstanding columns are read from the loan's
 * projections immediately before the insert, and:
 *
 *     write_off_amount
 *       == outstanding_principal_at_writeoff
 *        + outstanding_interest_at_writeoff
 *        + outstanding_penalty_at_writeoff
 *
 * The invariant is guaranteed by construction in `LoanWriteOffService`
 * (A.31), which computes the sum from the same three values it writes.
 * The schema does not enforce it (a cross-column CHECK on monetary values
 * is not portably expressible via Laravel's migration DSL, and the
 * platform's precedent is to enforce such invariants at the service layer
 * with a clearer error).
 *
 * The redundant `write_off_amount` column is retained because:
 *
 *   1. It is the single scalar that a report or an audit row displays as
 *      "the amount forgiven". Summing the three underlying columns at
 *      every read site would push the arithmetic into every consumer.
 *
 *   2. A future Change Request that introduces a PARTIAL write-off (a
 *      write-off that forgives less than the loan's full outstanding
 *      balance — currently forbidden by the frozen flow) would use
 *      `write_off_amount` as the amount actually forgiven, distinct from
 *      the amounts discharged. Keeping the column today makes that future
 *      change additive rather than a schema rewrite.
 *
 * =============================================================================
 * Why each "at write-off" column is stored explicitly
 * =============================================================================
 * The three columns are derivable in principle: at write-off time, they
 * equal the loan's `outstanding_principal`, `outstanding_interest`, and
 * `outstanding_penalty` projections (A.9). After the write-off, those
 * projections are zeroed, and the historical values are no longer readable
 * from the loan row.
 *
 * The write-off row therefore carries them explicitly to preserve the
 * historical fact. The audit row is self-contained: a reader of this table
 * alone can reconstruct the loan's financial state at the moment of
 * write-off without needing to consult a history table or a superseded
 * projection.
 *
 * The same reasoning was applied to the `previous_*` columns on
 * `loan_restructurings` (A.12).
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Two foreign keys:
 *
 *     loan_id         → loans.id   RESTRICT
 *     written_off_by  → users.id   SET NULL (nullable)
 *
 * The `loan_id` FK is RESTRICT per Q6 and the Phase 2B precedent: a hard
 * delete of a loan must not cascade into its write-off record. The loan
 * itself is never hard-deleted through any code path; the RESTRICT makes
 * an administrative delete fail loudly.
 *
 * The actor FK uses `nullOnDelete()` because the actor is metadata, not an
 * aggregate fact; a hard-deleted user detaches from the write-off, which
 * survives with a null actor. This mirrors the pattern on `wallets` (Phase
 * 2B), `loans` (A.9), `loan_collaterals` (A.11), and
 * `loan_restructurings` (A.12).
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * One composite index supports the Phase 2C query patterns:
 *
 *   (loan_id, written_off_at)  — "list a loan's write-off history in
 *                                 chronological order", the primary read
 *                                 pattern for the loan's detail view and
 *                                 for the audit trail.
 *
 * Because a loan can be written off at most once, this table has at most
 * one row per `loan_id`. The composite index's `written_off_at` trailing
 * column is therefore redundant for uniqueness purposes but preserves the
 * platform's chronological-read convention. The index also serves the FK's
 * implicit index requirement on `loan_id` — no separate single-column
 * index is declared.
 *
 * No index on `written_off_by`. No Phase 2C query filters write-offs by
 * actor. The FK's implicit index suffices for the "which write-offs did
 * this user perform?" query, should a future report require it.
 *
 * No index on `write_off_amount`. No 2C query filters or sorts write-offs
 * by amount.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The "at most one write-off per loan" invariant. Enforced by
 *     `LoanStateMachine` (A.26): the `written_off` state is terminal
 *     (A.2), so no transition back to a state from which a second
 *     write-off is possible exists. Adding a UNIQUE constraint on
 *     `loan_id` would express the invariant at the schema level, but it
 *     would also foreclose a future Change Request that permits a
 *     partial write-off followed by a further write-off (a legitimate
 *     pattern in some lending systems). The service-layer enforcement
 *     is the correct choice given the freeze.
 *
 *   - The `write_off_amount == sum of the three outstanding_at_writeoff
 *     columns` invariant. Enforced by `LoanWriteOffService` (A.31), which
 *     computes all four values from the same source.
 *
 *   - The positivity of the amount columns. Enforced by the service at
 *     write time; a negative or zero write-off is meaningless.
 *
 *   - The non-emptiness of `reason` after trimming. Enforced by the
 *     service; the schema enforces only the maximum length and the NOT
 *     NULL constraint.
 *
 *   - The presence of `written_off_by`. The column is nullable at the
 *     schema level; the service always supplies a value when the
 *     authenticated user is present. A future Change Request that
 *     introduces system-initiated write-offs would legitimately leave
 *     the column null, and the schema already accommodates that.
 *
 *   - Any linkage to a Wallet-domain entry. The Wallet's discharge entries
 *     reference this table's primary key through `wallet_entries.
 *     reference_id` as an opaque string, with `reference_type =
 *     'LoanWriteOff'`. There is no FK between the two domains; cross-domain
 *     references are string-keyed by design (per the Phase 2B reference
 *     pattern).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (write-off is a
 *                                                  lifecycle event), A10
 *                                                  (no automatic
 *                                                  enforcement; write-off is
 *                                                  the accounting-side
 *                                                  terminal action)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze
 *                                                  includes written_off),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.3 (reference-id
 *                                                  ordering), §3 (the
 *                                                  invariant that a
 *                                                  write-off preserves)
 *   Step 5 (manifest) A.13                       — This file
 *   Step 6 v1.1.0 §3.13                          — loan_write_offs table
 *                                                  specification
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_write_offs` table. Foreign keys target the `loans`
     * table (A.9) and the Phase 1 `users` table, both of which must
     * already exist when this migration runs.
     *
     * Migration ordering: A.9 (`loans`, prefix `200020`) runs before this
     * file (prefix `200060`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_write_offs', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            //
            // This primary key is used as the `reference_id` of every
            // Wallet discharge entry that the write-off produces. See the
            // class docblock §"Transactional role" for the ordering
            // rationale.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier; used as Wallet reference_id by the write-off transaction');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its write-off record (Q6).
            //
            // The composite index (loan_id, written_off_at) declared
            // below provides the FK's implicit index on `loan_id` — no
            // separate single-column index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('loan_id')
                  ->constrained('loans')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning loan');

            // -----------------------------------------------------------------
            // Amount forgiven.
            //
            // The total amount written off. Under the frozen write-off
            // flow, this equals the sum of the three "at write-off"
            // columns below. The invariant is maintained by
            // `LoanWriteOffService` (A.31); the schema does not enforce it.
            //
            // See the class docblock §"The 'at write-off' columns and the
            // sum invariant" for the rationale on carrying this scalar
            // alongside the three components.
            //
            // DECIMAL(20,4) matches every monetary column on the platform.
            // -----------------------------------------------------------------
            $table->decimal('write_off_amount', 20, 4)
                  ->comment('Total amount forgiven; equals the sum of the three *_at_writeoff columns');

            // -----------------------------------------------------------------
            // At-write-off snapshot — the loan's outstanding projections
            // captured immediately before this row was inserted.
            //
            // After the write-off completes, the loan's projections are
            // zeroed (per the frozen flow). These columns preserve the
            // historical values so that the audit row is self-contained.
            //
            // All three are NOT NULL: the values are always known at
            // write-off time (they are read from the loan's projections
            // immediately before the insert).
            //
            // All three are DECIMAL(20,4), matching the platform's
            // monetary precision and the shape of `loans.outstanding_*`
            // (A.9).
            // -----------------------------------------------------------------
            $table->decimal('outstanding_principal_at_writeoff', 20, 4)
                  ->comment('Loan outstanding principal at the moment of write-off');

            $table->decimal('outstanding_interest_at_writeoff', 20, 4)
                  ->comment('Loan outstanding interest at the moment of write-off');

            $table->decimal('outstanding_penalty_at_writeoff', 20, 4)
                  ->comment('Loan outstanding penalty at the moment of write-off');

            // -----------------------------------------------------------------
            // Reason — required free-text justification.
            //
            // The service requires a non-empty trimmed value on every
            // write-off. The column is NOT NULL; the trim check is a
            // service-layer concern, not a schema one.
            //
            // VARCHAR(500) matches every other reason/description column
            // on the platform (loans.purpose, loan_collaterals.description,
            // loan_restructurings.reason, wallet_entries.description, etc.).
            // -----------------------------------------------------------------
            $table->string('reason', 500)
                  ->comment('Required: why the write-off was recorded');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The moment the write-off was recorded. Distinct from
            // `created_at` (which is the row's insert timestamp); the two
            // will typically coincide in Phase 2C, but the semantic
            // separation is preserved for future use.
            //
            // `useCurrent()` provides a sensible default at insert time.
            // The service always supplies the value explicitly.
            // -----------------------------------------------------------------
            $table->timestamp('written_off_at')
                  ->useCurrent()
                  ->comment('Moment the write-off was recorded');

            // -----------------------------------------------------------------
            // Actor.
            //
            // The user who performed the write-off. Nullable and
            // `nullOnDelete()`: a hard-deleted user detaches, the row
            // survives with a null actor.
            //
            // Nullable also accommodates a future Change Request that
            // introduces system-initiated write-offs (for example, an
            // end-of-fiscal-year policy that forgives sub-threshold
            // balances). The schema is forward-compatible; the Phase 2C
            // service always supplies a value when the authenticated user
            // is present.
            // -----------------------------------------------------------------
            $table->foreignId('written_off_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who performed the write-off; nullable');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // The row carries `created_at` and `updated_at` per the
            // platform convention. The row is immutable in practice: no
            // service updates it after insert. See the class docblock
            // §"Immutability" for the rationale on carrying `updated_at`
            // on an append-only table.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Composite index.
            //
            // The composite (loan_id, written_off_at) provides the FK's
            // implicit index on `loan_id`. Because a loan can be written
            // off at most once, the index's trailing column is redundant
            // for uniqueness but preserves the platform's chronological-
            // read convention.
            // -----------------------------------------------------------------
            $table->index(
                ['loan_id', 'written_off_at'],
                'loan_write_offs_loan_date_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No other Phase 2C migration references this table
     * via a foreign key (the Wallet discharge entries reference the
     * write-off by `reference_id`, which is an opaque string, not an FK).
     * A rollback of this migration is therefore independent of any other
     * Phase 2C table's rollback order.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_write_offs');
    }
};