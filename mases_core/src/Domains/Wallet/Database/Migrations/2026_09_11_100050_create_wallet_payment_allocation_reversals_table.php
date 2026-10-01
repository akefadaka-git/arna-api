<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 7 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100050_create_wallet_payment_allocation_reversals_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~20 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('reversed_at')
 *         ->comment('Business time the reversal was recorded');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 *   The reversal row is documented as FULLY IMMUTABLE (Architecture
 *   §3.6). The Wallet model's booted() guard for reversals rejects any
 *   Eloquent-mediated update. Under the ON UPDATE clause, the schema
 *   contradicted the model's own discipline: any UPDATE that reached the
 *   row through a path bypassing the guard — a manual DB edit, an
 *   administrative script, a future CR that touches the table — would
 *   silently rewrite `reversed_at` to the moment of the update.
 *
 * After (amended source):
 *   $table->dateTime('reversed_at')
 *         ->comment('Business time the reversal was recorded');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 * Every other line — every column, comment, the defensive
 * `Schema::dropIfExists` at the top of up(), the explicit short-named
 * foreign key for `payment_allocation_id`, and the `down()` method — is
 * byte-for-byte identical to the source.
 *
 * Other TIMESTAMP columns on this table, NOT amended:
 *   · created_at, updated_at — from `$table->timestamps()`. Both applied
 *     as `timestamp NULL DEFAULT NULL`; no defect exists. `updated_at`
 *     is present for Laravel-standard shape and will always equal
 *     `created_at` in practice, because the model's booted() guard
 *     rejects any update.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Migration filename:        unchanged
 * Migration timestamp:       unchanged
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `wallet_payment_allocation_reversals` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A reversal is the immutable record that a specific portion of a
 * previously-recorded payment allocation has been undone. It is the sole
 * correction mechanism for allocations in Phase 2B (D110, Architecture
 * §3.6, amendment A1).
 *
 * A row in this table links:
 *
 *     one PaymentAllocation   (the allocation being corrected)
 *     with
 *     a positive reversed_amount (the portion of the allocation being undone)
 *     a required reason           (why the correction is being made)
 *     an actor                    (reversed_by)
 *     a business timestamp        (reversed_at)
 *
 * The original allocation row remains untouched. The reversal row is a
 * separate, permanent record. The effective allocated amount of the parent
 * allocation is reduced by the sum of its reversals:
 *
 *     effective_allocated(allocation)
 *         = allocation.amount
 *         − Σ(reversal.reversed_amount for all reversals of that allocation)
 *
 * The table is a DEPENDENT REVERSAL ENTITY of the Wallet domain
 * (Architecture §3.6). It is not an aggregate root; it exists only in the
 * context of the allocation it corrects.
 *
 * =============================================================================
 * Why a dedicated reversal entity (Architecture §3.6, amendment A1)
 * =============================================================================
 * The 2B architecture explicitly chose a dedicated reversal entity over
 * mutating the allocation. The rationale, recorded in amendment A1:
 *
 *   1. Attribution history must remain unambiguous. If the allocation's
 *      amount column were reducible in place, an observer could not tell
 *      whether the original allocation was smaller or whether a reversal
 *      had occurred.
 *
 *   2. The reversal records WHY the correction happened (the required
 *      `reason`) and WHO made it (`reversed_by`) — metadata that has no
 *      place on the original allocation row.
 *
 *   3. Partial reversals must be representable. A single reversal record
 *      with an explicit `reversed_amount` is the mechanism.
 *
 * The invariant that keeps the wallet domain internally coherent:
 *
 *     effective_allocated(allocation) ≥ 0
 *
 * which the service enforces by requiring:
 *
 *     reversal.reversed_amount
 *         ≤ effective_allocated(parent allocation) before this reversal
 *
 * =============================================================================
 * Full immutability (Architecture §3.6)
 * =============================================================================
 * A row in this table is FULLY IMMUTABLE once recorded. It has no
 * projection column, no mutable metadata, and no lifecycle beyond
 * creation. Every attribute is set once at insert time by
 * AllocationService (2B.18).
 *
 * The `updated_at` column IS present for Laravel-standard table shape
 * (Step 6 v1.1.0 §4.2). It will always equal `created_at` in practice:
 * the model's `booted()` guard (2B.9 §"Full immutability") rejects ANY
 * update attempt.
 *
 * A reversal cannot itself be reversed (Architecture §6.5 invariant 4).
 * If a correction to a correction is ever needed, the correct remedy is a
 * new allocation with a new allocation record — not a "reversal of the
 * reversal". This constraint is enforced at the service layer, not by the
 * schema, because a "reversal of a reversal" would be a new allocation
 * row with new semantics, not a row in this table.
 *
 * =============================================================================
 * Business time immutability — reversed_at is DATETIME (v1.0.2)
 * =============================================================================
 * `reversed_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. That would
 * contradict the row's own full-immutability contract: any UPDATE that
 * reached the row through a path bypassing the model's `booted()` guard
 * — a manual DB edit, an administrative maintenance script, or a future
 * CR that touches this table — would silently rewrite the business time
 * of the reversal.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by `AllocationService::reverse()` at
 * insertion time and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME and
 * TIMESTAMP both store a second-resolution instant interpreted in the
 * connection timezone. The model's `datetime` cast, the model's
 * `booted()` immutability guard, and every service-layer write are
 * unaffected.
 *
 * See also P2.2's companion amendments: the Shareholder domain
 * (`share_holding_entries.occurred_at`), the other five Wallet-domain
 * columns, and the Policy capability
 * (`versioned_policies.effective_from`). All eight amendments apply the
 * same DATETIME correction for the same reason.
 *
 * =============================================================================
 * Not a ledger entry (Architecture §5.1)
 * =============================================================================
 * Reversals do NOT produce `wallet_entries` rows. They are attribution
 * records, not monetary records. The monetary effect of the payment's
 * arrival and the obligation's recording was already realized when those
 * rows were written; the allocation, and now its reversal, are
 * reclassifications of that monetary fact.
 *
 * Consequence: creating a reversal does not change
 * `wallet.current_balance`. It changes the two derived projections —
 * `wallet_payments.unapplied_amount` (increases) and
 * `wallet_obligations.outstanding_amount` (increases) — by the reversed
 * amount, inside AllocationService's transaction.
 *
 * =============================================================================
 * The `reason` field
 * =============================================================================
 * `reason` is REQUIRED: NOT NULL at the schema level and enforced
 * non-empty by AllocationService (2B.18 §"assertReasonIsProvided"). It is
 * the metadata that distinguishes a reversal from a mere mutation
 * (Architecture §3.6). A reversal without a recorded reason would
 * undermine the attribution audit trail that the reversal entity was
 * introduced to preserve.
 *
 * The column is VARCHAR(500), matching the analogous `description`
 * columns on the sibling tables (2B.50, 2B.51) and the FormRequest's cap
 * (2B.25).
 *
 * =============================================================================
 * No currency column (Q8)
 * =============================================================================
 * This table carries no `currency` column. The currency of a reversal is
 * unambiguous from its ancestry: the parent allocation resolves to a
 * payment and an obligation, both of which carry a currency, and the Q8
 * invariant ensures both equal the wallet's currency. Recording a fourth
 * copy on the reversal would add a multi-way equality that the schema
 * cannot enforce and that the service would have to maintain in lockstep.
 *
 * =============================================================================
 * The denormalized `wallet_id` column
 * =============================================================================
 * This table carries a `wallet_id` column denormalized from the parent
 * allocation (which itself denormalizes from the payment/obligation). The
 * column exists so that:
 *
 *   - Wallet-scoped queries can filter reversals directly, without
 *     traversing the parent allocation.
 *
 *   - The tenant-isolation test matrix (Step 7 v1.1.0 §C.3) has a direct
 *     `wallet_id` column to assert against.
 *
 * The denormalization is safe because the service sets the id in the same
 * operation that creates the reversal, and no code path updates it
 * afterwards.
 *
 * =============================================================================
 * Foreign keys (Q6, Architecture §17.3)
 * =============================================================================
 * Three foreign keys:
 *
 *     wallet_id              → wallets.id                    RESTRICT
 *     payment_allocation_id  → wallet_payment_allocations.id RESTRICT
 *     reversed_by            → users.id                      SET NULL
 *
 * Q6 mandates RESTRICT / NO ACTION for every wallet-owned financial FK:
 * cascade deletes of financial history are forbidden. Both wallet-domain
 * FKs (`wallet_id`, `payment_allocation_id`) are RESTRICT.
 *
 * The `reversed_by` FK is `nullOnDelete()` because the actor is metadata,
 * not an aggregate fact. Same rationale as the sibling tables (2B.50,
 * 2B.51, 2B.52).
 *
 * =============================================================================
 * The shortened FK name — single-statement form (Step 6 v1.1.0 §5.2)
 * =============================================================================
 * Laravel's default FK constraint name for the `payment_allocation_id`
 * column would be:
 *
 *     wallet_payment_allocation_reversals_payment_allocation_id_foreign
 *
 * which is 65 characters — one character over MySQL's 64-character
 * limit. MySQL rejects the identifier with error 1059 ("Identifier name
 * ... is too long").
 *
 * The initial delivery of this migration attempted a two-statement
 * workaround: use `->constrained()` to let Laravel create the FK with
 * the default name, then drop it and re-add it with a shorter name in a
 * second `Schema::table()` block. That approach FAILED at migration
 * time, because Laravel 11+ on MySQL emits the `->constrained()` FK as
 * a SEPARATE `ALTER TABLE ... ADD CONSTRAINT` issued *after* the
 * `CREATE TABLE`. The long name reaches MySQL first, MySQL rejects it
 * with error 1059, and the migration aborts.
 *
 * The correct approach is the SINGLE-STATEMENT form used below:
 *
 *   - Declare the column with `foreignId('payment_allocation_id')` and
 *     NO `->constrained()` call.
 *   - Add the FK explicitly inside the same `Schema::create()` closure
 *     via `$table->foreign('payment_allocation_id', 'wpar_payment_allocation_id_foreign')
 *     ->references('id')->on('wallet_payment_allocations')...`.
 *
 * This produces the FK with the short name in one step. The default
 * (long) name never reaches MySQL. The pattern is driver-agnostic — it
 * does not depend on whether Laravel inlines the FK into the CREATE
 * TABLE or emits it as a separate ALTER TABLE, because the only FK that
 * is ever requested carries the short name.
 *
 * The short name `wpar_payment_allocation_id_foreign` is 34 characters —
 * comfortably under the 64-character limit.
 *
 * The `wallet_id` and `reversed_by` FKs use the default Laravel names,
 * both of which are within the 64-character limit (56 and 58 characters
 * respectively). They continue to use the `->constrained()` chain.
 *
 * =============================================================================
 * The one-time defensive `dropIfExists` (v1.0.2)
 * =============================================================================
 * The `up()` method begins with:
 *
 *     Schema::dropIfExists('wallet_payment_allocation_reversals');
 *
 * This is a ONE-TIME cleanup for the following exact scenario, which
 * occurred during Phase 2B deployment:
 *
 *   1. The 2B.53 v1.0.0 migration attempted `CREATE TABLE` followed by
 *      a separate `ALTER TABLE ... ADD CONSTRAINT` for the FK. The
 *      `CREATE TABLE` succeeded; the `ALTER TABLE` failed with MySQL
 *      error 1059 (identifier too long). The migration was not recorded
 *      in the `migrations` table.
 *
 *   2. MySQL does NOT roll back a successful `CREATE TABLE` when a
 *      subsequent statement in the same migration fails. The table
 *      therefore exists in an orphaned state: all columns present, no
 *      FK, no migration record.
 *
 *   3. Re-running the corrected migration without this defensive line
 *      would fail with MySQL error 1050 (table already exists), because
 *      the orphan shadows the new `CREATE TABLE`.
 *
 * On a fresh install (or after this deployment), the `dropIfExists` is a
 * harmless no-op — `DROP TABLE IF EXISTS` on a non-existent table
 * succeeds silently. It is idempotent and has no side effects beyond
 * removing the specific orphan described above.
 *
 * A future Change Request can remove this defensive line once the
 * migration has been confirmed healthy in every deployed environment.
 * Until then, the cost of keeping it (a single `DROP TABLE IF EXISTS`
 * round-trip) is negligible.
 *
 * =============================================================================
 * Indexes (Step 6 v1.1.0 S6-D)
 * =============================================================================
 * The migration declares NO explicit `$table->index(...)` call. Every
 * index required by the 2B query patterns is FK-implied:
 *
 *   PRIMARY KEY      id
 *   FK-supported     wallet_id                          (implicit)
 *   FK-supported     payment_allocation_id              (implicit)
 *   FK-supported     reversed_by                        (implicit)
 *
 * All three FKs receive their implicit single-column index from MySQL's
 * FK creation. No composite or plain index is required: no 2B query
 * pattern filters reversals by a combination of columns or by a single
 * non-FK column. A future CR that adds such a pattern would add the
 * corresponding index.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The four §6.5 reversal invariants. Each is enforced by
 *     AllocationService inside its transaction, using the locked parent
 *     rows:
 *
 *         1. reversal.reversed_amount > 0
 *         2. reversal.reversed_amount ≤ effective_allocated(parent) before
 *            this reversal
 *         3. parent allocation is not itself a reversal (structurally
 *            guaranteed — allocations and reversals are distinct model
 *            classes in distinct tables)
 *         4. a reversal row is never itself reversed (enforced by the
 *            absence of any operation that would accept a
 *            PaymentAllocationReversal as a reversal target)
 *
 *   - The `reason` non-empty check. The column is NOT NULL, but a
 *     whitespace-only value would satisfy that constraint; the
 *     service's `assertReasonIsProvided()` rejects it.
 *
 *   - The `reversed_amount > 0` bound. Enforced by the service and by the
 *     FormRequest (2B.25) at the request layer.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `wallet_payment_allocation_reversals` table. Foreign
     * keys target the `wallets` table (2B.49), the
     * `wallet_payment_allocations` table (2B.52), and the Phase 1 `users`
     * table — all of which must already exist when this migration runs.
     *
     * The method begins with a defensive `dropIfExists` — see the
     * class-level docblock §"The one-time defensive `dropIfExists`" for
     * the exact scenario it addresses.
     *
     * @return void
     */
    public function up(): void
    {
        // ---------------------------------------------------------------------
        // One-time defensive cleanup. Removes the orphaned table left by
        // the failed 2B.53 v1.0.0 attempt. See the class-level docblock
        // §"The one-time defensive `dropIfExists`" for the full scenario.
        //
        // On a fresh install (or after this deployment) this is a
        // harmless no-op. It is idempotent — `DROP TABLE IF EXISTS` on a
        // non-existent table succeeds silently.
        // ---------------------------------------------------------------------
        Schema::dropIfExists('wallet_payment_allocation_reversals');

        Schema::create('wallet_payment_allocation_reversals', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Denormalized wallet scope.
            //
            // Set to the same value as the parent allocation's `wallet_id`.
            // Enables wallet-scoped queries without traversing the parent
            // allocation. See the class-level docblock §"The denormalized
            // wallet_id column".
            //
            // On delete: restrict. A hard delete of the wallet must not
            // cascade into its reversal history (Q6, Architecture §17.3).
            //
            // No explicit single-column index is declared; the FK's
            // implicit index is the sole index on this column.
            // -----------------------------------------------------------------
            $table->foreignId('wallet_id')
                  ->constrained('wallets')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Denormalized wallet scope (matches the parent allocation)');

            // -----------------------------------------------------------------
            // The parent allocation being reversed.
            //
            // On delete: restrict. A hard delete of the parent allocation
            // must not cascade into its reversal history (Q6,
            // Architecture §17.3).
            //
            // NO `->constrained()` call on this column. The FK is added
            // explicitly below with a SHORT name, because Laravel's
            // default name for this constraint would be 65 characters —
            // over MySQL's 64-character limit. See the class-level
            // docblock §"The shortened FK name — single-statement form"
            // for the rationale.
            //
            // No explicit single-column index is declared; the FK's
            // implicit index is the sole index on this column.
            // -----------------------------------------------------------------
            $table->foreignId('payment_allocation_id')
                  ->comment('Parent allocation being reversed');

            // -----------------------------------------------------------------
            // Explicit FK for `payment_allocation_id`, with the short name
            // required by MySQL's 64-character identifier limit.
            //
            // Added inside the same `Schema::create()` closure so that
            // the FK is created alongside the column with the SHORT name
            // from the outset. The default (long) name never reaches
            // MySQL.
            //
            // `cascadeOnUpdate()` and `restrictOnDelete()` mirror the
            // deletion and update policies used by every other FK in the
            // 2B schema (Q6, Architecture §17.3).
            // -----------------------------------------------------------------
            $table->foreign(
                'payment_allocation_id',
                'wpar_payment_allocation_id_foreign',
            )
                  ->references('id')
                  ->on('wallet_payment_allocations')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            // -----------------------------------------------------------------
            // Amount (Architecture §6.5).
            //
            // The POSITIVE portion of the parent allocation being undone.
            // Bounded above by the parent allocation's effective allocated
            // amount before this reversal (invariant 2).
            //
            // DECIMAL(20,4) matches every other monetary column in the
            // Wallet domain.
            //
            // IMMUTABLE after creation: the model's booted() guard (2B.9)
            // rejects ANY update. There is no correction-of-correction
            // operation in 2B.
            // -----------------------------------------------------------------
            $table->decimal('reversed_amount', 20, 4)
                  ->comment('Positive amount reversed; immutable after creation');

            // -----------------------------------------------------------------
            // Reason (Architecture §3.6).
            //
            // NOT NULL. Enforced non-empty (whitespace-trim) by the
            // service (2B.18 §"assertReasonIsProvided"). The reason is the
            // metadata that distinguishes a reversal from a mere
            // mutation; see the class-level docblock §"The reason field".
            //
            // VARCHAR(500) matches the analogous `description` columns on
            // the sibling tables and the FormRequest's cap (2B.25).
            // -----------------------------------------------------------------
            $table->string('reason', 500)
                  ->comment('Required: why the reversal was recorded');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The business time of the reversal. Distinct from
            // `created_at`: an administrative backfill may insert a
            // reversal now for a correction that occurred earlier.
            //
            // DATETIME, not TIMESTAMP. See the class docblock §"Business
            // time immutability — reversed_at is DATETIME". The type
            // change eliminates the server-mode-dependent implicit
            // DEFAULT / ON UPDATE behavior that would otherwise rewrite
            // the business time of a reversal on any UPDATE that reached
            // the row through a path bypassing the model's guard.
            // -----------------------------------------------------------------
            $table->dateTime('reversed_at')
                  ->comment('Business time the reversal was recorded');

            // -----------------------------------------------------------------
            // Actor.
            //
            // On delete: nullify. A hard-deleted User detaches from the
            // reversal; the reversal survives with a null actor.
            //
            // Nullable at the schema level for forward compatibility with
            // a future automated correction workflow. The Phase 2B service
            // requires a user on every call.
            // -----------------------------------------------------------------
            $table->foreignId('reversed_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who recorded the reversal (nullable)');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // `updated_at` is present for Laravel-standard table shape
            // (Step 6 v1.1.0 §4.2). It will always equal `created_at` in
            // practice: the model's booted() guard (2B.9) rejects ANY
            // update attempt.
            //
            // Both `created_at` and `updated_at` are nullable TIMESTAMP
            // columns and are not affected by the DATETIME correction.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // NO explicit index is declared. Every index required by the
            // 2B query patterns is FK-implied:
            //
            //   wallet_id              — implicit single-column index
            //   payment_allocation_id  — implicit single-column index
            //                            (created by the explicit FK above)
            //   reversed_by            — implicit single-column index
            //
            // No composite or plain index is required. See the class-level
            // docblock §"Indexes".
            // -----------------------------------------------------------------
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table carries no self-referencing FK,
     * no additional constraint manipulation is required before the drop;
     * MySQL removes the table's constraints as part of `DROP TABLE`.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_payment_allocation_reversals');
    }
};