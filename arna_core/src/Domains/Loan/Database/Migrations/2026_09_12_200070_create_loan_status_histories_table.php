<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_status_histories` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per status transition on a loan. Every time a loan's `status`
 * column (A.9) changes, exactly one row is appended here recording the
 * transition: the prior status, the new status, the moment of the change,
 * the actor who performed it, and an optional reason.
 *
 * The table is APPEND-ONLY. Rows are never updated and never deleted. It is
 * the authoritative historical record of the loan's lifecycle; the
 * `loans.status` column is the CURRENT state, not the history.
 *
 * The distinction matters for audit. A reader of `loans` sees a loan's
 * state as of now. A reader of this table sees the entire path the loan
 * took to reach that state, including every intermediate state, the actor
 * who drove each transition, and the moment of each transition. The two
 * views are complementary: the loan row is the fast path for "what is the
 * state?"; the history is the authoritative record for "how did it get
 * here?".
 *
 * =============================================================================
 * What this table is NOT
 * =============================================================================
 *   - It is NOT the loan's schedule history. Schedule changes are recorded
 *     in `loan_installments` (A.10) — a superseded schedule's rows are
 *     transitioned to `superseded` status, and the new schedule's rows are
 *     created. The restructuring itself is recorded in
 *     `loan_restructurings` (A.12).
 *
 *   - It is NOT the loan's financial history. Payments, disbursements, and
 *     monetary effects are recorded on the Wallet side (Phase 2B) and, for
 *     the loan domain's summary, in the loan's own projections. The status
 *     history captures the lifecycle state changes, not the monetary
 *     transactions that accompanied them.
 *
 *   - It is NOT a general audit log. The platform's general audit trail
 *     (`audit_logs`, Phase 1) records every domain action with a broader
 *     payload. The status history is a focused, loan-scoped log whose
 *     purpose is to make the loan's lifecycle path reconstructible
 *     without reading the general audit table.
 *
 *   - It is NOT a duplicate of the loan domain's events. The thirteen
 *     domain events (A.38–A.50) are dispatched after-commit and handled
 *     by `AuditLoanEvent` (A.51), which writes to `audit_logs`. The status
 *     history is written INSIDE the transition transaction, before commit;
 *     it is the durable record of the state change itself, independent of
 *     whether the after-commit event dispatch succeeds.
 *
 * =============================================================================
 * The append-only discipline
 * =============================================================================
 * The table is append-only by construction and by convention:
 *
 *   - The migration declares `created_at` as a nullable TIMESTAMP and does
 *     NOT declare `updated_at`. This mirrors the append-only pattern
 *     established by `share_holding_entries` (Phase 2A, migration 2A.40)
 *     and `wallet_entries` (Phase 2B, migration 2B.54).
 *
 *   - The `LoanStatusHistory` model (A.21) sets `$timestamps = false` and
 *     declares no `update` or `delete` methods.
 *
 *   - The only writer is `LoanStateMachine` (A.26), which inserts one row
 *     per transition inside the transition's transaction.
 *
 *   - No service, controller, or repository path updates or deletes a row.
 *
 * A future Change Request that introduces a "correct a mis-recorded
 * transition" operation would write a NEW row (a corrected entry with a
 * distinguishing note), not update the existing one. Financial-adjacent
 * history is never mutated.
 *
 * =============================================================================
 * `from_status` is nullable
 * =============================================================================
 * The first history row for a loan may be a "created" entry with no prior
 * status: the loan did not exist before this moment. The
 * `LoanStateMachine` (A.26) writes such a row when the loan is initially
 * created in `draft`, with `from_status = null` and `to_status = 'draft'`.
 *
 * All subsequent rows have a non-null `from_status`, because every
 * subsequent transition originates from an existing state. The migration
 * does not enforce "non-null after the first row" — that would require a
 * partial constraint that MySQL does not express portably. The invariant
 * is a service-layer concern: `LoanStateMachine` is the only writer, and
 * it writes a null `from_status` only on the initial row.
 *
 * A reader that needs "the state the loan was in before transition T"
 * reads T's `from_status` column directly; a null value unambiguously
 * identifies the initial-creation row.
 *
 * =============================================================================
 * `to_status` is NOT NULL
 * =============================================================================
 * Every history row records the state the loan ENTERED. There is no
 * history row whose purpose is to record a state the loan LEFT without
 * entering a new one — a state change always has both endpoints (the
 * prior state is `from_status`, the new state is `to_status`), except for
 * the initial-creation row where the prior state did not exist.
 *
 * The NOT NULL constraint makes this explicit: the row is always about
 * the state that was ENTERED.
 *
 * =============================================================================
 * `transition_reason`
 * =============================================================================
 * A free-text justification for the transition, supplied by the actor.
 * Some transitions require a reason (rejection, write-off, restructuring —
 * the three lifecycle transitions whose business meaning depends on the
 * operator's judgment); others do not (submission, approval, disbursement —
 * transitions that are self-explanatory from their endpoints).
 *
 * The schema allows NULL on all transitions; the service layer enforces
 * the per-transition requirements:
 *
 *     draft → submitted          : no reason required
 *     submitted → under_review   : no reason required (automatic)
 *     under_review → approved    : no reason required
 *     under_review → rejected    : reason REQUIRED
 *     approved → disbursed       : no reason required
 *     disbursed → active         : no reason required (automatic)
 *     active → in_arrears        : no reason required (automatic)
 *     active → settled           : no reason required (automatic)
 *     active → written_off       : reason REQUIRED
 *     in_arrears → restructured  : reason REQUIRED
 *     in_arrears → settled       : no reason required (automatic)
 *     in_arrears → written_off   : reason REQUIRED
 *     restructured → active      : no reason required (automatic)
 *     restructured → settled     : no reason required (automatic)
 *     restructured → written_off : reason REQUIRED
 *     settled → closed           : no reason required (automatic)
 *
 * The per-transition requirement is not expressible as a portable
 * schema constraint; it is a service-layer concern enforced by
 * `LoanStateMachine` (A.26) and by the request classes that drive
 * operator-initiated transitions (`RejectLoanRequest`, `StoreWriteOffRequest`,
 * `StoreRestructureRequest`).
 *
 * =============================================================================
 * `actor_user_id` is nullable
 * =============================================================================
 * The user who performed the transition. Nullable because:
 *
 *   1. Some transitions are AUTOMATIC — driven by the domain's own logic
 *      rather than by an operator. The `submitted → under_review`
 *      transition, the `disbursed → active` transition, and the
 *      `active → in_arrears` transition, among others, are performed by
 *      the system in response to a business event, without a human
 *      actor. Those rows carry a null actor.
 *
 *   2. A system-initiated transition (from a future CR that introduces
 *      scheduled lifecycle actions) would also carry a null actor.
 *
 *   3. A hard-deleted user detaches from the historical row, which
 *      survives with a null actor (the FK's `nullOnDelete()` behavior).
 *
 * The distinction between "the transition had no human actor" and "the
 * actor was deleted" is not recoverable from this table alone; a reader
 * that needs to distinguish the two consults the general `audit_logs`
 * trail (which captures the actor at the time of the action and is not
 * affected by later user deletions).
 *
 * =============================================================================
 * The business time vs. the row-insert time
 * =============================================================================
 * The row carries two timestamps:
 *
 *     occurred_at   — the moment the transition occurred, per the
 *                     domain's clock. This is the semantically meaningful
 *                     time: the moment the loan's state changed.
 *
 *     created_at    — the moment the row was inserted into the database.
 *                     Always equals `occurred_at` in practice, because
 *                     `LoanStateMachine` writes both from the same
 *                     `Carbon::now()` value inside one transaction.
 *
 * The two are kept separate because:
 *
 *   1. The platform convention for append-only history tables
 *      (`share_holding_entries`, `wallet_entries`) carries both, with
 *      `created_at` serving as a durable marker of insertion time even if
 *      the domain-level `occurred_at` is supplied explicitly.
 *
 *   2. A future Change Request that introduces back-dated transitions (for
 *      example, an import of historical loan records) would legitimately
 *      set `occurred_at` to a past moment while leaving `created_at` at
 *      the insert time. The two-column design accommodates that without
 *      a schema change.
 *
 * In Phase 2C, no code path produces a divergence between the two values.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Two foreign keys:
 *
 *     loan_id        → loans.id  RESTRICT
 *     actor_user_id  → users.id  SET NULL (nullable)
 *
 * The `loan_id` FK is RESTRICT per Q6 and the Phase 2B precedent: a hard
 * delete of a loan must not cascade into its status history. The loan
 * itself is never hard-deleted through any code path; the RESTRICT makes
 * an administrative delete fail loudly.
 *
 * The actor FK uses `nullOnDelete()` because the actor is metadata, not an
 * aggregate fact; a hard-deleted user detaches from the history row,
 * which survives with a null actor. This mirrors the pattern on `wallets`
 * (Phase 2B), `loans` (A.9), `loan_collaterals` (A.11),
 * `loan_restructurings` (A.12), and `loan_write_offs` (A.13).
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two composite indexes support the Phase 2C query patterns:
 *
 *   (loan_id, occurred_at)  — "list a loan's transitions in chronological
 *                             order", the primary read pattern for the
 *                             loan's detail view, for the audit trail, and
 *                             for any lifecycle-path reconstruction.
 *
 *   (loan_id, id)           — "list a loan's transitions in insertion
 *                             order", the deterministic tiebreaker when
 *                             two transitions share an identical
 *                             `occurred_at` timestamp (possible when a
 *                             transaction triggers two automatic
 *                             transitions in the same instant, e.g.
 *                             `disbursed → active` followed immediately by
 *                             `active → in_arrears`).
 *
 * The `loan_id` FK's implicit index is satisfied by the first composite's
 * leading column; no separate single-column index on `loan_id` is
 * declared.
 *
 * No index on `from_status` or `to_status`. No Phase 2C query filters
 * transitions by state; the per-loan chronological read is the only
 * pattern. A future report that enumerates "all loans currently in state
 * X" reads `loans.status`, not this table.
 *
 * No index on `actor_user_id`. No Phase 2C query filters transitions by
 * actor. The FK's implicit index suffices for the "which transitions did
 * this user perform?" query, should a future report require it.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The state transition matrix. Enforced by `LoanStateMachine` (A.26),
 *     which composes `LoanStatus::canTransitionTo()` (A.2) with guards,
 *     permissions, and event dispatch.
 *
 *   - The per-transition reason requirements. Enforced by
 *     `LoanStateMachine` (A.26) and by the request classes for
 *     operator-initiated transitions.
 *
 *   - The "from_status is null only on the initial row" invariant.
 *     Enforced by `LoanStateMachine` (A.26), which is the sole writer.
 *
 *   - The equality between `occurred_at` and `created_at`. Guaranteed by
 *     `LoanStateMachine` (A.26) in Phase 2C; the schema allows a
 *     divergence for future back-dated writes.
 *
 *   - Any uniqueness on the pair (loan_id, from_status, to_status). A
 *     loan may legitimately transition through the same pair twice — for
 *     example, a loan that enters `in_arrears`, is settled, and later
 *     (after a new disbursement, which is not possible in the current
 *     state machine) enters `in_arrears` again. In Phase 2C the state
 *     machine's structural matrix prevents most such cycles, but a
 *     schema-level uniqueness would be a stronger constraint than the
 *     freeze requires and would close off future CRs.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2A migration 2A.40                     — `share_holding_entries`
 *                                                  append-only pattern
 *   Phase 2B migration 2B.54                     — `wallet_entries`
 *                                                  append-only pattern
 *   Step 5 (manifest) A.14                       — This file
 *   Step 6 v1.1.0 §3.14                          — loan_status_histories
 *                                                  table specification
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_status_histories` table. Foreign keys target the
     * `loans` table (A.9) and the Phase 1 `users` table, both of which
     * must already exist when this migration runs.
     *
     * Migration ordering: A.9 (`loans`, prefix `200020`) runs before this
     * file (prefix `200070`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_status_histories', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its status history (Q6).
            //
            // The composite index (loan_id, occurred_at) declared below
            // provides the FK's implicit index on `loan_id` — no separate
            // single-column index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('loan_id')
                  ->constrained('loans')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning loan');

            // -----------------------------------------------------------------
            // Prior status.
            //
            // The state the loan was in BEFORE this transition. Nullable:
            // the initial-creation row (which records the loan's entry
            // into `draft`) has no prior state. See the class docblock
            // §"`from_status` is nullable" for the full rationale.
            //
            // VARCHAR(32) matches the `loans.status` column width (A.9)
            // and accommodates every `LoanStatus` value with headroom
            // (the longest is `under_review`, 12 characters).
            //
            // The column stores a `LoanStatus` value (A.2). It is NOT a
            // MySQL ENUM; adding a state updates the enum, not the
            // schema.
            // -----------------------------------------------------------------
            $table->string('from_status', 32)
                  ->nullable()
                  ->comment('LoanStatus value before the transition; null on initial creation');

            // -----------------------------------------------------------------
            // New status.
            //
            // The state the loan ENTERED as a result of this transition.
            // NOT NULL: every history row records a state the loan is now
            // in.
            //
            // VARCHAR(32) matches `from_status` and `loans.status`.
            // -----------------------------------------------------------------
            $table->string('to_status', 32)
                  ->comment('LoanStatus value after the transition');

            // -----------------------------------------------------------------
            // Transition reason.
            //
            // A free-text justification for the transition. Nullable at
            // the schema level; the service layer requires a non-empty
            // value on the four transitions whose business meaning
            // depends on the operator's judgment (rejection, write-off ×3,
            // restructure). See the class docblock
            // §"`transition_reason`" for the per-transition requirements.
            //
            // VARCHAR(500) matches every other reason/description column
            // on the platform.
            // -----------------------------------------------------------------
            $table->string('transition_reason', 500)
                  ->nullable()
                  ->comment('Optional free-text reason; required by the service on rejection, restructure, and write-off');

            // -----------------------------------------------------------------
            // Actor.
            //
            // The user who performed the transition, or null for an
            // automatic transition. Nullable per the class docblock
            // §"`actor_user_id` is nullable".
            //
            // On delete: nullify. A hard-deleted user detaches from the
            // history row; the row survives with a null actor.
            // -----------------------------------------------------------------
            $table->foreignId('actor_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who performed the transition; null for automatic transitions');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The moment the transition occurred. `useCurrent()` provides
            // a sensible default at insert time; the service supplies the
            // value explicitly so that the timestamp is consistent across
            // every row written in the same transaction.
            //
            // The `created_at` column (below) records the row's insert
            // time. In Phase 2C the two coincide; the separation exists
            // for future back-dated writes. See the class docblock
            // §"The business time vs. the row-insert time".
            // -----------------------------------------------------------------
            $table->timestamp('occurred_at')
                  ->useCurrent()
                  ->comment('Moment the transition occurred');

            // -----------------------------------------------------------------
            // Insert time.
            //
            // Nullable, matching the platform's append-only history-table
            // convention: `share_holding_entries.created_at` (Phase 2A,
            // migration 2A.40) and `wallet_entries.created_at` (Phase 2B,
            // migration 2B.54) are both nullable.
            //
            // There is NO `updated_at` column. The table is append-only;
            // no row is ever updated. The `LoanStatusHistory` model (A.21)
            // sets `$timestamps = false` accordingly.
            //
            // The column is written explicitly by `LoanStateMachine`
            // (A.26) from the same `Carbon::now()` value that produces
            // `occurred_at`, keeping the two values aligned by
            // construction.
            // -----------------------------------------------------------------
            $table->timestamp('created_at')
                  ->nullable()
                  ->comment('Row insert timestamp; written explicitly, no updated_at');

            // -----------------------------------------------------------------
            // Composite indexes.
            //
            // The composite (loan_id, occurred_at) provides the FK's
            // implicit index on `loan_id`. The composite (loan_id, id) is
            // a deterministic tiebreaker for same-instant transitions.
            // -----------------------------------------------------------------

            // "List a loan's transitions in chronological order" — the
            // primary read pattern.
            $table->index(
                ['loan_id', 'occurred_at'],
                'loan_status_histories_loan_date_index',
            );

            // "List a loan's transitions in insertion order" —
            // deterministic tiebreaker for same-instant transitions.
            $table->index(
                ['loan_id', 'id'],
                'loan_status_histories_loan_id_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No other Phase 2C migration references this table
     * via a foreign key. A rollback of this migration is therefore
     * independent of any other Phase 2C table's rollback order.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_status_histories');
    }
};