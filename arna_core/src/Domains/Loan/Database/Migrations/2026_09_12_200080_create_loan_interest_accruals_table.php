<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_interest_accruals` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per interest accrual recognized against a loan installment. The
 * table is the Loan domain's bookkeeping for the interest obligations it
 * has recorded on the Wallet: each row links a specific installment to
 * the Wallet obligation that was created when the installment's due date
 * arrived.
 *
 * The table does NOT carry the monetary ledger — that belongs to the
 * Wallet domain (Phase 2B, `wallet_obligations` and `wallet_entries`). It
 * carries the LOAN-side record of what has already been accrued, so that
 * the scheduled accrual command can be run repeatedly without creating
 * duplicate Wallet obligations.
 *
 * =============================================================================
 * The idempotency role (Phase 2C Final Reconciliation §4.2)
 * =============================================================================
 * The table's central purpose is idempotency. The
 * `AccrueInterestCommand` (A.87) runs daily on the Plesk scheduler. It
 * examines every loan in an accrual-eligible state and, for each
 * installment whose due date is today, records an interest obligation on
 * the borrower's Wallet.
 *
 * Without this table, a duplicate run of the command — from a scheduler
 * retry, a manual invocation, a command that overlaps with the previous
 * day's still-running instance, or any other cause — would create a
 * SECOND interest obligation for the same installment. That is not
 * merely a performance issue: it is a financial correctness issue. The
 * borrower would owe interest twice.
 *
 * The table's `UNIQUE (accrual_period_key)` constraint prevents this at
 * the database level:
 *
 *   1. The command builds a deterministic key from the loan and
 *      installment it is about to accrue against:
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
 *      Wallet, and stores the resulting Wallet obligation's id on the
 *      new row (`wallet_obligation_id`).
 *
 *   4. The whole sequence runs inside one transaction. If the Wallet
 *      call fails after the accrual row was inserted, the transaction
 *      rolls back, and the next command run retries the installment from
 *      a clean state.
 *
 * The uniqueness is therefore not a soft "please don't duplicate" check;
 * it is a schema-enforced guarantee that a given installment can accrue
 * interest at most once in the current accrual scheme.
 *
 * =============================================================================
 * The accrual key format, and the non-daily-accrual freeze
 * =============================================================================
 * The key format `"{loan_id}:{installment_id}"` reflects the FROZEN
 * semantics of the current accrual scheme:
 *
 *     ONE interest obligation per installment, recognized at the
 *     installment's due date. Not daily accrual. Not periodic
 *     compounding. One accrual per installment, ever.
 *
 * The command name `AccrueInterestCommand` could be misread as a
 * daily-interest engine, and the fact that the command itself runs daily
 * on the scheduler reinforces that misreading. The command's class
 * docblock (A.87) and this migration's docblock together record the
 * freeze explicitly:
 *
 *     Phase 2C does NOT implement daily interest accrual. It
 *     recognizes one scheduled interest obligation per installment,
 *     at the installment's due date.
 *
 * If a future Change Request introduces true daily accrual, that CR must:
 *
 *   1. Extend the key format to include a date component:
 *
 *          accrual_period_key = "{loan_id}:{installment_id}:{YYYY-MM-DD}"
 *
 *   2. Rename this table (or add a sibling) if the schema shape changes
 *      materially — a daily-accrual row carries a specific accrual date,
 *      whereas the current row's `recorded_at` is meaningful only as
 *      the moment of the single accrual.
 *
 *   3. Update the `AccrueInterestCommand` to iterate by date range
 *      rather than by "installments whose due date is today".
 *
 * The VARCHAR(64) width of `accrual_period_key` accommodates the current
 * format (at most 41 characters for two bigint ids and a colon separator)
 * and the future date-suffixed format (at most 52 characters). No schema
 * change is required for that future CR to be expressible; only the
 * command's logic and this docblock's freeze language need updating.
 *
 * =============================================================================
 * Why a dedicated table and not a column on `loan_installments`
 * =============================================================================
 * The `loan_installments` table (A.10) carries the schedule and the
 * settlement state. Its `status` column tracks whether the installment is
 * pending, partially paid, settled, overdue, or superseded. There is no
 * existing column that records "has this installment's interest been
 * accrued yet?".
 *
 * A dedicated table, rather than an extra column, was chosen for four
 * reasons:
 *
 *   1. **Audit completeness.** The table records WHEN the accrual was
 *      recognized (`recorded_at`) and WHICH Wallet obligation it
 *      produced (`wallet_obligation_id`). Both facts are lost if the
 *      accrual is reduced to a boolean column on the installment.
 *
 *   2. **Schema symmetry with penalty accruals.** The sibling table
 *      `loan_penalty_accruals` (A.16) has the same shape and the same
 *      idempotency role for the arrears-detection command (A.88). Two
 *      sibling tables with parallel shape and purpose are clearer than
 *      one table with a discriminator column.
 *
 *   3. **Forward-compatibility with daily accrual.** A future CR that
 *      introduces daily accrual would add rows on a per-day cadence; the
 *      dedicated table accommodates that without contorting a single
 *      column on `loan_installments`.
 *
 *   4. **Separation of concerns.** The installment row records the
 *      CONTRACTUAL schedule (what should happen). The accrual row
 *      records the OPERATIONAL fact that a specific Wallet obligation
 *      was created (what has actually been recorded). Keeping the two
 *      concerns on separate rows honors the B2 hybrid-ledger discipline.
 *
 * =============================================================================
 * The `wallet_obligation_id` reference
 * =============================================================================
 * When the command succeeds in inserting the accrual row, it calls
 * `ObligationService::record()` on the Wallet (Phase 2B, `wallet_obligations`
 * + `wallet_entries`). The Wallet returns the created obligation, and the
 * command updates this row's `wallet_obligation_id` to that obligation's
 * id.
 *
 * The column is NULLABLE because the two operations — inserting this row
 * and calling the Wallet — happen in one transaction, and the row must be
 * insertable BEFORE the Wallet obligation's id is known:
 *
 *   BEGIN TRANSACTION
 *     INSERT INTO loan_interest_accruals (..., wallet_obligation_id = NULL)
 *       → obtains accrual row id; UNIQUE constraint on accrual_period_key
 *         either admits the insert or rejects a duplicate
 *     CALL ObligationService::record() on the Wallet
 *       → obtains wallet obligation id
 *     UPDATE loan_interest_accruals SET wallet_obligation_id = <id>
 *   COMMIT
 *
 * If the Wallet call fails, the transaction rolls back. If the UPDATE
 * fails, the same. On a successful run, the row is committed with a
 * non-null `wallet_obligation_id`. On a failed run, no row exists at all.
 *
 * The nullable state is therefore transient: it exists only inside an
 * open transaction. A reader of the committed table sees only rows with
 * non-null `wallet_obligation_id` — except in the pathological case where
 * a future Change Request splits the transaction (for example, a
 * two-phase commit), which the current freeze does not require.
 *
 * The FK on this column uses `restrictOnDelete()`: the Wallet obligation
 * is a financial fact and must not be silently removed while this row
 * references it. Versioned policies aside, no Wallet obligation row is
 * ever deleted through any code path; the RESTRICT is defensive against
 * an administrative delete.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * A row of this table is immutable once committed:
 *
 *   - The `wallet_obligation_id` UPDATE described above happens INSIDE
 *     the transaction that inserts the row; from the perspective of any
 *     OTHER reader, the row appears complete. No later code path
 *     modifies it.
 *
 *   - The `LoanInterestAccrual` model (A.18) sets `$timestamps = false`
 *     for `updated_at` semantics — see below — and exposes no mutating
 *     methods beyond the initial creation and the one-time
 *     `wallet_obligation_id` write.
 *
 *   - There is NO `updated_at` column. The table is treated as an
 *     append-only ledger of accruals, matching the convention of
 *     `share_holding_entries` (Phase 2A, migration 2A.40) and
 *     `wallet_entries` (Phase 2B, migration 2B.54). The single
 *     `wallet_obligation_id` write is performed by the command's own
 *     transaction and is invisible to any interleaved reader; the
 *     platform's append-only discipline is preserved at the reader
 *     level.
 *
 *   - The `created_at` column is written explicitly by the command. It
 *     is nullable at the schema level, matching the platform's
 *     append-only-table convention for this column.
 *
 * A future Change Request that introduces a correction to an accrual
 * (for example, correcting a mis-applied interest amount) would write a
 * NEW row (a correction or reversal record) or would reverse the Wallet
 * obligation through the Wallet's reversal mechanism and record the
 * reversal here. The existing row is never mutated.
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
 * The `loan_id` FK is RESTRICT per Q6 and the Phase 2B precedent: a hard
 * delete of a loan must not cascade into its accrual history. The loan is
 * never hard-deleted through any code path; the RESTRICT makes an
 * administrative delete fail loudly.
 *
 * The `installment_id` FK is RESTRICT for the same reason: an installment
 * row is never hard-deleted through any code path (a restructure
 * supersedes it rather than deleting it; see A.10), and an accrual row
 * must not be orphaned by a hypothetical administrative delete.
 *
 * The `wallet_obligation_id` FK is RESTRICT (nullable) for the reasons
 * documented in §"The `wallet_obligation_id` reference".
 *
 * The cross-domain FK (Loan → Wallet) is worth noting explicitly. Unlike
 * the Wallet domain, which writes an opaque reference to a Loan source
 * object through `reference_type = 'Loan'` + `reference_id = <string>`
 * (no FK), the Loan domain here points at a specific Wallet obligation
 * by its primary key. This is a deliberate asymmetry:
 *
 *   - The Wallet's reference-type vocabulary is designed to be
 *     CROSS-DOMAIN and OPAQUE: the Wallet does not need to know what a
 *     "Loan" or a "LoanRepayment" is, and every consuming domain uses
 *     the same string-keyed reference mechanism.
 *
 *   - The Loan domain's accrual bookkeeping is SPECIFIC: it needs to
 *     link each of its accrual rows to the exact Wallet obligation it
 *     created, so that the reconciliation service (A.32) can verify that
 *     the loan-linked obligation population matches the loan's
 *     projections.
 *
 * Both designs are consistent with the platform's architecture: the
 * Wallet is the shared monetary ledger with an opaque reference
 * vocabulary; the Loan is a domain that depends on the Wallet and can
 * afford to hold a specific FK.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two indexes support the Phase 2C query patterns:
 *
 *   UNIQUE (accrual_period_key)  — the idempotency constraint; also
 *                                  serves as the primary lookup for
 *                                  "has this installment already been
 *                                  accrued?".
 *
 *   (loan_id, recorded_at)       — "list a loan's interest accruals in
 *                                  chronological order", the read
 *                                  pattern used by the reconciliation
 *                                  service (A.32) and by the loan's
 *                                  detail view. The composite's leading
 *                                  column also serves as the FK-supporting
 *                                  index on `loan_id`, so MySQL does not
 *                                  create a separate single-column index
 *                                  for that FK.
 *
 * The `installment_id` FK receives MySQL's implicit single-column index
 * automatically; no explicit index is declared. The `wallet_obligation_id`
 * FK receives the same treatment.
 *
 * No index on `interest_amount` or on `recorded_at` alone. No Phase 2C
 * query filters or sorts by either in isolation.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The "one accrual per installment" rule beyond the UNIQUE constraint
 *     on `accrual_period_key`. Because the key is deterministic from
 *     `(loan_id, installment_id)`, the UNIQUE constraint is exactly the
 *     one-accrual-per-installment rule, expressed through the key.
 *
 *   - The positivity of `interest_amount`. Enforced by
 *     `LoanInterestAccrualService` (A.28) at write time. A negative or
 *     zero interest amount is a service defect, not a legitimate state.
 *
 *   - The consistency between `interest_amount` and the installment's
 *     `expected_interest`. The two should be equal in the standard
 *     schedule, but a restructuring may rewrite the installment while
 *     preserving the accrual (the accrual was already recognized; the
 *     restructuring does not un-recognize it). The reconciliation
 *     service (A.32) is the layer that surfaces any divergence.
 *
 *   - The presence of `wallet_obligation_id` on committed rows. As
 *     documented above, the column is nullable because the two-step
 *     write happens inside one transaction; a committed row always has
 *     a non-null value in practice.
 *
 *   - The re-accrual of interest after a restructure. When a loan is
 *     restructured, the previous schedule's installments are superseded
 *     (A.10) and their corresponding Wallet obligations are discharged
 *     (A.12). Their accrual rows on this table remain untouched — the
 *     fact that interest was once recognized is a historical fact and is
 *     preserved. New installments from the new schedule will accrue
 *     interest on their own due dates, generating new accrual rows.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9 (interest method),
 *                                                  B3 (interest recording
 *                                                  pattern)
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
 *   Step 5 (manifest) A.15                       — This file
 *   Step 6 v1.1.0 §3.15                          — loan_interest_accruals
 *                                                  table specification
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_interest_accruals` table. Foreign keys target the
     * `loans` table (A.9), the `loan_installments` table (A.10), and the
     * Phase 2B `wallet_obligations` table (2B.50) — all of which must
     * already exist when this migration runs.
     *
     * Migration ordering: A.9 (prefix `200020`) and A.10 (prefix `200030`)
     * both run before this file (prefix `200080`). Phase 2B migration
     * 2B.50 (prefix `100020`) also runs before this file.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_interest_accruals', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // The loan this accrual belongs to. Redundant with the
            // `installment_id`'s transitive link (an installment belongs
            // to exactly one loan), but stored explicitly so that the
            // reconciliation service and reporting queries can filter by
            // loan without joining through `loan_installments`.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its accrual history (Q6).
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
            // Accrued installment.
            //
            // The installment whose due-date arrival triggered this
            // accrual. On delete: restrict. An installment is never
            // hard-deleted through any code path (a restructure
            // supersedes it rather than deleting it; see A.10), and an
            // accrual row must not be orphaned by a hypothetical
            // administrative delete.
            //
            // The FK's implicit single-column index is provided
            // automatically by MySQL — no explicit index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('installment_id')
                  ->constrained('loan_installments')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Installment whose due date triggered this accrual');

            // -----------------------------------------------------------------
            // Accrual period key.
            //
            // The deterministic idempotency key for this accrual, in the
            // format:
            //
            //     "{loan_id}:{installment_id}"
            //
            // The key is built by `LoanInterestAccrualService` (A.28) and
            // stored as-is. Its uniqueness (enforced below) is what makes
            // the daily `AccrueInterestCommand` (A.87) idempotent across
            // duplicate runs, overlapping instances, and manual
            // invocations. See the class docblock §"The idempotency role"
            // and §"The accrual key format" for the full rationale.
            //
            // VARCHAR(64) accommodates the current format (at most 41
            // characters for two bigint ids and a colon separator) and a
            // future date-suffixed format (at most 52 characters) without
            // a schema change.
            // -----------------------------------------------------------------
            $table->string('accrual_period_key', 64)
                  ->comment('Deterministic key "{loan_id}:{installment_id}"; the idempotency constraint');

            // -----------------------------------------------------------------
            // Interest amount.
            //
            // The interest amount recognized for this installment,
            // computed by `LoanInterestAccrualService` (A.28) from the
            // loan's frozen `loan_product_rules` policy. The value is
            // passed verbatim to `ObligationService::record()` on the
            // Wallet with component `ObligationComponent::Interest`.
            //
            // NOT NULL: the amount is always known at the moment the row
            // is inserted; a null would indicate a service defect.
            //
            // DECIMAL(20,4) matches every monetary column on the
            // platform.
            // -----------------------------------------------------------------
            $table->decimal('interest_amount', 20, 4)
                  ->comment('Interest amount recognized for this installment');

            // -----------------------------------------------------------------
            // Wallet obligation reference.
            //
            // The `wallet_obligations.id` of the obligation that this
            // accrual produced on the borrower's Wallet. Nullable because
            // the row is inserted BEFORE the Wallet call (which is what
            // enforces the idempotency), and the Wallet obligation's id
            // is written back into the row inside the same transaction.
            //
            // A committed row always has a non-null value in practice.
            // See the class docblock §"The `wallet_obligation_id`
            // reference" for the full transactional sequence.
            //
            // On delete: restrict. Wallet obligations are financial facts
            // and must not be silently removed while this row references
            // them. No Wallet obligation is ever hard-deleted through any
            // code path; the RESTRICT is defensive against an
            // administrative delete.
            //
            // The FK's implicit single-column index is provided
            // automatically by MySQL — no explicit index is declared.
            // -----------------------------------------------------------------
            $table->foreignId('wallet_obligation_id')
                  ->nullable()
                  ->constrained('wallet_obligations')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Wallet obligation produced by this accrual; written back inside the transaction');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The moment the interest was recognized. `useCurrent()`
            // provides a sensible default at insert time; the service
            // supplies the value explicitly. In the standard accrual
            // path, `recorded_at` equals the installment's `due_date`
            // (at the day's start-of-business time), because the command
            // recognizes interest on the installment's due date. The
            // column type is TIMESTAMP (not DATE) so that a future CR
            // that introduces intra-day accrual has a stable shape.
            // -----------------------------------------------------------------
            $table->timestamp('recorded_at')
                  ->useCurrent()
                  ->comment('Moment the interest was recognized');

            // -----------------------------------------------------------------
            // Insert time.
            //
            // Nullable, matching the platform's append-only-table
            // convention: `share_holding_entries.created_at` (Phase 2A,
            // migration 2A.40), `wallet_entries.created_at` (Phase 2B,
            // migration 2B.54), and `loan_status_histories.created_at`
            // (A.14) are all nullable.
            //
            // There is NO `updated_at` column. The table is treated as
            // append-only at the reader level — the single
            // `wallet_obligation_id` write happens inside the row's own
            // insert transaction and is invisible to any interleaved
            // reader. See the class docblock §"Immutability".
            //
            // The `LoanInterestAccrual` model (A.18) sets
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
            // one-accrual-per-installment rule. It also serves as the
            // primary lookup for "has this installment already been
            // accrued?".
            $table->unique(
                'accrual_period_key',
                'loan_interest_accruals_key_unique',
            );

            // "List a loan's interest accruals in chronological order" —
            // the reconciliation read pattern and the loan detail view.
            // The composite's leading column also satisfies the
            // `loan_id` FK's implicit index requirement.
            $table->index(
                ['loan_id', 'recorded_at'],
                'loan_interest_accruals_loan_date_index',
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
        Schema::dropIfExists('loan_interest_accruals');
    }
};