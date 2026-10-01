<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_restructurings` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per restructuring event on a loan. A restructuring is the
 * renegotiation of a loan's contractual terms: the principal, the term, the
 * policy version, or any combination thereof are replaced, and a new
 * schedule is generated in place of the prior schedule's installments.
 *
 * Each row is an immutable historical record: the state of the loan at the
 * moment of the restructure, the terms that replace it, and the actor and
 * reason. The row is NEVER updated and NEVER deleted.
 *
 * Multiple restructures on the same loan are permitted. A loan's current
 * terms reflect its most recent restructure; the sequence of rows on this
 * table is the complete restructuring history.
 *
 * =============================================================================
 * Transactional role — the reference-id ordering fix (Phase 2C Final
 * Reconciliation §1.2)
 * =============================================================================
 * This table is the FIRST write in the restructure transaction, before any
 * Wallet discharge occurs. The reason is that the Wallet discharge
 * operations need a stable `reference_id` to identify the restructuring
 * that caused them, and that `reference_id` is this table's generated
 * primary key.
 *
 * The frozen restructure transaction is:
 *
 *   1. Validate loan state and permission.
 *   2. Compute previous outstanding (from the loan's projections).
 *   3. INSERT this row → obtain $restructuringId.  ← THIS MIGRATION'S ROLE
 *   4. For each outstanding Wallet obligation linked to the loan:
 *        ObligationService::discharge(
 *          ...,
 *          referenceType: 'LoanRestructure',
 *          referenceId:   (string) $restructuringId,
 *        );
 *   5. Generate the new installment schedule.
 *   6. Recompute the loan's projections.
 *   7. Transition the loan's status (active|in_arrears → restructured →
 *      active).
 *   8. Register after-commit dispatch of `LoanRestructured`.
 *   COMMIT
 *
 * If any step after step 3 fails, the entire transaction rolls back —
 * including the row inserted into this table. The atomicity is preserved;
 * the ordering only ensures that the generated id is available to step 4.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * A row of this table is immutable once written:
 *
 *   - There is no code path that updates a row after insert. The
 *     `LoanRestructuring` model (A.19) sets `$timestamps = false` for
 *     `updated_at` semantics? No — see below.
 *
 *   - The `updated_at` column IS present (via `timestamps()`), because
 *     Laravel's Eloquent requires it by default and because the table
 *     follows the platform's convention for historical-fact tables:
 *     mutation is prevented at the SERVICE layer, not by removing the
 *     column. This mirrors `wallet_payment_allocation_reversals`
 *     (Phase 2B, migration 2B.53) and `share_holding_entries`
 *     (Phase 2A, migration 2A.40) — both of which are append-only in
 *     practice but carry standard timestamps.
 *
 *   - A future Change Request that introduces a "reverse a restructure"
 *     operation would write a NEW restructuring row that reverses the
 *     prior one; it would NOT update this row.
 *
 * =============================================================================
 * Previous vs. new terms
 * =============================================================================
 * The row carries two groups of columns:
 *
 *   Previous terms (the state at the moment of restructure):
 *       previous_policy_version_id
 *       previous_outstanding_principal
 *       previous_outstanding_interest
 *       previous_outstanding_penalty
 *       previous_maturity_date
 *       previous_installment_count
 *
 *   New terms (what replaces them):
 *       new_policy_version_id
 *       new_principal_amount
 *       new_term_months
 *       new_maturity_date
 *       new_total_scheduled_repayment
 *       new_installment_count
 *
 * The two groups are deliberately exhaustive: a reader of this table alone
 * can reconstruct the full before/after of the restructure without
 * querying any other table. This is required for audit — the loan's own
 * row reflects only the CURRENT terms (post-restructure); the previous
 * terms live here.
 *
 * Every monetary column is DECIMAL(20,4), matching the platform-wide
 * monetary precision. Term and count columns are INTEGER.
 *
 * =============================================================================
 * Maturity dates are DATETIME, not TIMESTAMP
 * =============================================================================
 * Both `previous_maturity_date` and `new_maturity_date` are declared as
 * DATETIME, not TIMESTAMP. A maturity date is a business date — the
 * literal calendar date on which the schedule's final installment falls —
 * not an audit instant. It is deliberately:
 *
 *   - NOT subject to MySQL's TIMESTAMP → UTC-on-write /
 *     session-timezone-on-read conversion. A DATETIME is stored as the
 *     literal wall-clock value the service produced and is read back
 *     byte-for-byte. A TIMESTAMP would silently shift the value under
 *     a non-UTC session timezone, corrupting the audit record.
 *
 *   - NOT subject to `explicit_defaults_for_timestamp` behavior. A
 *     `TIMESTAMP NOT NULL` column without an explicit default is
 *     rejected by MySQL under `explicit_defaults_for_timestamp=ON`
 *     (error 1067, "Invalid default value"), and silently coerced to
 *     `CURRENT_TIMESTAMP` when the setting is OFF — both wrong for a
 *     business date. DATETIME has no such implicit-default semantics.
 *
 * The platform's rule is:
 *
 *   - Business dates (due dates, maturity dates, effective dates)
 *     → DATETIME (or DATE, when only the day is meaningful).
 *   - Audit instants (created_at, updated_at, occurred_at, ...)
 *     → TIMESTAMP (because the UTC normalization is desired there).
 *
 * `restructured_at` follows the second rule and remains TIMESTAMP.
 *
 * =============================================================================
 * Why previous_maturity_date is stored (not derivable)
 * =============================================================================
 * The prior schedule's final installment is superseded during the
 * restructure, and its `due_date` is preserved on the superseded row (per
 * A.10). In principle, `previous_maturity_date` could be derived by
 * querying the highest-dated superseded installment.
 *
 * The value is nonetheless stored explicitly because:
 *
 *   1. It makes the audit row self-contained — an auditor reads ONE row,
 *      not a join.
 *
 *   2. A future Change Request that purges superseded installments (out
 *      of Phase 2C scope, but plausible) would remove the derivation
 *      source. Storing the value guards against that future.
 *
 *   3. The value is a scalar fact of the historical moment; storing it
 *      costs 8 bytes and eliminates any chance of a derivation defect.
 *
 * The same reasoning applies to `previous_installment_count` and
 * `new_installment_count`: both are derivable in principle, both are
 * stored for audit self-sufficiency.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Four foreign keys:
 *
 *     loan_id                    → loans.id               RESTRICT
 *     previous_policy_version_id → versioned_policies.id  RESTRICT (nullable)
 *     new_policy_version_id      → versioned_policies.id  RESTRICT (nullable)
 *     restructured_by            → users.id               SET NULL (nullable)
 *
 * The `loan_id` FK is RESTRICT per Q6 and the Phase 2B precedent: a hard
 * delete of a loan must not cascade into its restructuring history. The
 * two policy FKs are RESTRICT (nullable) because versioned policies are
 * immutable and cannot be deleted through any code path. The actor FK uses
 * `nullOnDelete()` because the actor is metadata, not an aggregate fact.
 *
 * The two policy FKs are nullable because:
 *
 *   - `previous_policy_version_id` is null if the loan predates the policy
 *     freeze (not possible in Phase 2C, but the column is defensive).
 *   - `new_policy_version_id` is null if the restructure does not change
 *     the policy (a restructure that changes only the principal or the
 *     term, keeping the same policy version, would set this column to the
 *     same value as `previous_policy_version_id` — or to null if the
 *     restructure does not warrant a distinct policy version).
 *
 * In practice, `LoanRestructuringService` (A.30) sets both columns on
 * every row. The nullable declaration is a schema-level flexibility, not
 * a domain-level recommendation.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two composite indexes support the Phase 2C query patterns:
 *
 *   (loan_id, restructured_at)  — "list a loan's restructure history in
 *                                 chronological order", the primary read
 *                                 pattern for the loan's detail view and
 *                                 for the audit trail.
 *
 *   (loan_id, id)               — "list a loan's restructure history in
 *                                 insertion order", the fallback when
 *                                 two restructures share an identical
 *                                 `restructured_at` timestamp (a rare but
 *                                 possible case for programmatic
 *                                 restructures). The `id` tiebreaker is
 *                                 deterministic.
 *
 * The `loan_id` FK's implicit index is satisfied by the first composite's
 * leading column; no separate single-column index on `loan_id` is declared.
 *
 * No index on either policy-version column. No 2C query filters
 * restructurings by policy version. A future report that needs it can be
 * added by its own CR.
 *
 * No index on `restructured_by`. No 2C query filters restructurings by
 * actor. The FK's implicit index suffices for the "which restructures did
 * this user perform?" query pattern, should a future report require it.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The relationship between `previous_*` and `new_*` values. For
 *     example, the schema does not require that
 *     `new_principal_amount != previous_outstanding_principal`; a
 *     restructure that changes only the term (keeping the principal) is
 *     legitimate.
 *
 *   - The presence of a valid `new_policy_version_id`. The schema permits
 *     null; the service supplies a non-null value on every row.
 *
 *   - The relationship between `previous_maturity_date` and the loan's
 *     prior schedule. Guaranteed by the service, which reads the prior
 *     schedule before the restructure.
 *
 *   - The relationship between `new_maturity_date` and the new schedule's
 *     final installment's due date. Guaranteed by the service, which
 *     computes both from the same source.
 *
 *   - The monotonicity of restructuring events on a single loan. The
 *     service permits any sequence; the ordering is enforced by
 *     `restructured_at` ascending, not by a constraint.
 *
 *   - Any application of the reason field beyond non-emptiness. The
 *     reason is free text; the service requires a non-empty trimmed value
 *     at insert time, and the schema stores it as-is.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (restructure is a
 *                                                  lifecycle event), B2
 *                                                  (Loan owns contractual
 *                                                  state)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze
 *                                                  includes restructured),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2 (reference-id
 *                                                  ordering)
 *   Step 5 (manifest) A.12                       — This file
 *   Step 6 v1.1.0 §3.12                          — loan_restructurings
 *                                                  table specification
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_restructurings` table. Foreign keys target the
     * `loans` table (A.9), the Core Policy capability's
     * `versioned_policies` table (CORE.2), and the Phase 1 `users` table —
     * all of which must already exist when this migration runs.
     *
     * Migration ordering: A.9 (`loans`, prefix `200020`) runs before this
     * file (prefix `200050`). CORE.2 (`versioned_policies`, prefix
     * `100001`) runs before every Phase 2C migration.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_restructurings', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            //
            // This primary key is used as the `reference_id` of every
            // Wallet discharge entry that the restructure produces. See
            // the class docblock §"Transactional role" for the ordering
            // rationale.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier; used as Wallet reference_id by the restructure transaction');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its restructuring history (Q6).
            //
            // The composite index (loan_id, restructured_at) declared
            // below provides the FK's implicit index on `loan_id` — no
            // separate single-column index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('loan_id')
                  ->constrained('loans')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning loan');

            // -----------------------------------------------------------------
            // Previous terms — the state at the moment of restructure.
            //
            // The five columns below snapshot the loan's key terms as they
            // stood immediately before this restructure. They are
            // immutable; a subsequent restructure writes a new row rather
            // than updating these.
            //
            // All monetary values are DECIMAL(20,4). Maturity dates are
            // DATETIME (business dates); see the class docblock §"Maturity
            // dates are DATETIME, not TIMESTAMP" for the rationale.
            // -----------------------------------------------------------------

            // The policy version in effect before the restructure. Nullable
            // because a pre-policy-freeze loan would have no version;
            // Phase 2C always populates it.
            //
            // On delete: restrict. Policies are immutable.
            $table->foreignId('previous_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Policy version in effect before the restructure; nullable');

            // The outstanding projections at the moment of restructure.
            // These are the values the loan's row carried immediately
            // before the restructure transaction began.
            $table->decimal('previous_outstanding_principal', 20, 4)
                  ->comment('Outstanding principal at the moment of restructure');

            $table->decimal('previous_outstanding_interest', 20, 4)
                  ->comment('Outstanding interest at the moment of restructure');

            $table->decimal('previous_outstanding_penalty', 20, 4)
                  ->comment('Outstanding penalty at the moment of restructure');

            // The maturity date of the prior schedule's final
            // installment. Stored explicitly for audit self-sufficiency;
            // see the class docblock §"Why previous_maturity_date is
            // stored".
            //
            // DATETIME, not TIMESTAMP: this is a business date, not an
            // audit instant. See the class docblock §"Maturity dates are
            // DATETIME, not TIMESTAMP".
            $table->dateTime('previous_maturity_date')
                  ->nullable()
                  ->comment('Maturity date of the prior schedule; nullable');

            // The count of installments in the prior schedule. Same
            // rationale as previous_maturity_date.
            $table->integer('previous_installment_count')
                  ->comment('Number of installments in the prior schedule');

            // -----------------------------------------------------------------
            // New terms — what replaces the previous state.
            // -----------------------------------------------------------------

            // The policy version that governs the restructured loan.
            // Nullable per the class docblock §"Foreign keys"; the service
            // supplies a value on every row.
            $table->foreignId('new_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Policy version governing the restructured loan; nullable');

            // The new principal amount. May equal previous_outstanding_principal
            // (a term-only restructure) or differ (a principal-reducing or
            // principal-inflating restructure). The service enforces a
            // positive value.
            $table->decimal('new_principal_amount', 20, 4)
                  ->comment('New principal amount after the restructure');

            // The new term in months. Must be positive; the service
            // validates against the governing policy's min/max months.
            $table->integer('new_term_months')
                  ->comment('New term in months after the restructure');

            // The new maturity date — the due date of the new schedule's
            // final installment. Stored explicitly for the same reason as
            // previous_maturity_date.
            //
            // DATETIME, not TIMESTAMP. A `TIMESTAMP NOT NULL` column
            // without an explicit default is rejected by MySQL under
            // `explicit_defaults_for_timestamp=ON` (error 1067, "Invalid
            // default value"), and silently coerced to CURRENT_TIMESTAMP
            // otherwise. Neither behavior is correct for a business date.
            // See the class docblock §"Maturity dates are DATETIME, not
            // TIMESTAMP".
            $table->dateTime('new_maturity_date')
                  ->comment('Maturity date of the new schedule');

            // The new schedule's total scheduled repayment
            // (principal + interest), for audit self-sufficiency.
            $table->decimal('new_total_scheduled_repayment', 20, 4)
                  ->comment('Total scheduled repayment across the new schedule');

            // The count of installments in the new schedule.
            $table->integer('new_installment_count')
                  ->comment('Number of installments in the new schedule');

            // -----------------------------------------------------------------
            // Reason — required free-text justification.
            //
            // The service requires a non-empty trimmed value on every
            // restructure. The column is NOT NULL; the trim check is a
            // service-layer concern, not a schema one.
            //
            // VARCHAR(500) matches every other reason/description column
            // on the platform.
            // -----------------------------------------------------------------
            $table->string('reason', 500)
                  ->comment('Required: why the restructure was recorded');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The moment the restructure was recorded. Distinct from
            // `created_at` (which is the row's insert timestamp); the two
            // will typically coincide in Phase 2C, but the semantic
            // separation is preserved for future use.
            //
            // TIMESTAMP (not DATETIME): this is an audit instant, and the
            // UTC normalization that TIMESTAMP applies is the desired
            // behavior here. Contrast with the two maturity-date columns
            // above.
            //
            // `useCurrent()` provides a sensible default at insert time.
            // The service always supplies the value explicitly.
            // -----------------------------------------------------------------
            $table->timestamp('restructured_at')
                  ->useCurrent()
                  ->comment('Moment the restructure was recorded');

            // -----------------------------------------------------------------
            // Actor.
            //
            // The user who performed the restructure. Nullable and
            // `nullOnDelete()`: a hard-deleted user detaches, the row
            // survives with a null actor.
            // -----------------------------------------------------------------
            $table->foreignId('restructured_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who performed the restructure; nullable');

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
            // Composite indexes.
            //
            // The composite (loan_id, restructured_at) provides the FK's
            // implicit index on `loan_id`. The composite (loan_id, id) is
            // a tiebreaker for reads ordered by insertion.
            // -----------------------------------------------------------------

            // "List a loan's restructure history in chronological order"
            // — the primary read pattern.
            $table->index(
                ['loan_id', 'restructured_at'],
                'loan_restructurings_loan_date_index',
            );

            // "List a loan's restructure history in insertion order" —
            // deterministic tiebreaker for same-instant restructures.
            $table->index(
                ['loan_id', 'id'],
                'loan_restructurings_loan_id_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No other Phase 2C migration references this table
     * via a foreign key (the Wallet discharge entries reference the
     * restructure by `reference_id`, which is an opaque string, not an
     * FK). A rollback of this migration is therefore independent of any
     * other Phase 2C table's rollback order.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_restructurings');
    }
};