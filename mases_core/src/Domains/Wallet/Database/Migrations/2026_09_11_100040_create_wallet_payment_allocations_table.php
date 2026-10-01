<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 6 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100040_create_wallet_payment_allocations_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~20 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('allocated_at')
 *         ->comment('Business time the allocation was recorded');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 *   The allocation row is documented as FULLY IMMUTABLE (Architecture
 *   §3.5). The Wallet model's booted() guard for allocations rejects any
 *   Eloquent-mediated update. Under the ON UPDATE clause, the schema
 *   contradicted the model's own discipline: any UPDATE that reached the
 *   row through a path bypassing the guard — a manual DB edit, an
 *   administrative script, a future CR that touches the table — would
 *   silently rewrite `allocated_at` to the moment of the update.
 *
 * After (amended source):
 *   $table->dateTime('allocated_at')
 *         ->comment('Business time the allocation was recorded');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 * Every other line — every column, comment, the explicit short-named
 * foreign key for `payment_allocation_id`, the composite index, and the
 * `down()` method — is byte-for-byte identical to the source.
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
 * Create the `wallet_payment_allocations` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * An allocation is the explicit record that a specific payment, in whole
 * or in part, has been applied to a specific obligation. It is the
 * mechanism by which the wallet attributes a received payment to the
 * components of debt it settles (D110).
 *
 * A row in this table links:
 *
 *     one Payment       (the money that arrived)
 *     to
 *     one Obligation    (the debt component that the money settles)
 *     with
 *     a positive amount (the portion of the payment being applied)
 *
 * The three — payment, obligation, amount — are supplied explicitly on
 * every allocation request. No universal priority order is encoded; the
 * business's allocation policy is a future concern (D110, Architecture
 * §10).
 *
 * The table is a DEPENDENT ATTRIBUTION ENTITY of the Wallet domain
 * (Architecture §3.5). It is not an aggregate root; it exists only in
 * the context of a payment and its target obligation.
 *
 * =============================================================================
 * Not a ledger entry (Architecture §5.1)
 * =============================================================================
 * Allocations do NOT produce `wallet_entries` rows. They are attribution
 * records, not monetary records. The wallet's ledger records the
 * payment's arrival and the obligation's recording; the allocation
 * records how the two are linked.
 *
 * Consequence: creating an allocation does not change
 * `wallet.current_balance`. The monetary effect of the payment was
 * already realized at the moment of recording; allocation is a
 * reclassification, not a movement of money.
 *
 * =============================================================================
 * Full immutability (Architecture §3.5, Step 6 v1.1.0 §4.2)
 * =============================================================================
 * A row in this table is FULLY IMMUTABLE once recorded. Unlike
 * `wallets`, `wallet_obligations`, and `wallet_payments` — which have
 * projection columns (`current_balance`, `outstanding_amount`,
 * `unapplied_amount`) that their respective services legitimately update
 * inside their transactions — an allocation has NO projection column and
 * NO mutable field.
 *
 * The `updated_at` column IS present on this table for Laravel-standard
 * table shape (Step 6 v1.1.0 §4.2). It will always equal `created_at` in
 * practice: the model's `booted()` guard (2B.8 §"Immutability") rejects
 * ANY update attempt, including one that would change no user-visible
 * attribute (Eloquent has already marked `updated_at` as dirty by the
 * time the `updating` event fires).
 *
 * The correct way to correct an allocation is to record a
 * `wallet_payment_allocation_reversals` row (2B.53) — a linked, immutable
 * record that reduces the allocation's effective amount without touching
 * the original row (Architecture §3.6). The effective allocated amount
 * becomes:
 *
 *     effective_allocated(allocation)
 *         = allocation.amount
 *         − Σ(reversal.reversed_amount for all reversals of this allocation)
 *
 * Two derived projection columns — `wallet_payments.unapplied_amount` and
 * `wallet_obligations.outstanding_amount` — are updated transactionally
 * by AllocationService (2B.18) whenever an allocation or its reversal is
 * recorded.
 *
 * =============================================================================
 * Business time immutability — allocated_at is DATETIME (v1.0.2)
 * =============================================================================
 * `allocated_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. That would
 * contradict the row's own full-immutability contract: any UPDATE that
 * reached the row through a path bypassing the model's `booted()` guard
 * — a manual DB edit, an administrative maintenance script, or a future
 * CR that touches this table — would silently rewrite the business time
 * of the allocation.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by `AllocationService::allocate()` at
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
 * Invariants enforced at the service layer (Architecture §6.3)
 * =============================================================================
 * The following constraints are NOT expressible in the schema and are
 * enforced by AllocationService inside its transaction:
 *
 *     1. allocation.amount > 0
 *     2. allocation.amount ≤ payment.unapplied_amount (at allocation time)
 *     3. allocation.amount ≤ obligation.outstanding_amount (at allocation time)
 *     4. payment.wallet_id == obligation.wallet_id
 *     5. payment.currency == obligation.currency == wallet.currency (Q8)
 *
 * Invariant 4 is structurally guaranteed by the service's two-stage
 * pre-flight resolution of `payment_id` and `obligation_id` through their
 * wallet-scoped repositories (2B.18 §"allocate"). Invariant 5 is checked
 * explicitly by the service. Invariants 1–3 are checked explicitly and
 * bounded by the locked parent rows.
 *
 * Invariant 4 is additionally reflected by the schema: this table carries
 * a denormalized `wallet_id` column (see below) which the service sets to
 * the same value as both parents' wallet. A cross-wallet allocation
 * cannot be created through any 2B code path.
 *
 * =============================================================================
 * The denormalized `wallet_id` column
 * =============================================================================
 * This table carries a `wallet_id` column denormalized from both
 * `payment.wallet_id` and `obligation.wallet_id` (which are guaranteed to
 * be equal by invariant 4). The column exists so that:
 *
 *   - Wallet-scoped queries can filter allocations directly, without
 *     traversing either parent.
 *
 *   - A future CR that adds a wallet-scoped reporting endpoint has a
 *     single, unambiguous scope column to index on.
 *
 *   - The tenant-isolation test matrix (Step 7 v1.1.0 §C.3) has a direct
 *     `wallet_id` column to assert against, mirroring the pattern
 *     established by 2A's `share_holding_entries`.
 *
 * The denormalization is safe because the service sets all three ids in
 * one operation and no code path updates them afterwards. A future CR
 * that introduces an operation capable of moving an allocation between
 * wallets would need to reconcile this column with the constraint's
 * enforcement.
 *
 * =============================================================================
 * No currency column (Q8)
 * =============================================================================
 * This table carries no `currency` column. The currency of an allocation
 * is unambiguous from its parents: the payment and the obligation both
 * carry a currency, and the Q8 invariant ensures both equal the wallet's
 * currency. Recording a third copy on the allocation would add a
 * three-way equality that the schema cannot enforce and that the service
 * would have to maintain in lockstep. Omitting the column is the correct
 * normalization.
 *
 * =============================================================================
 * Foreign keys (Q6, Architecture §17.3)
 * =============================================================================
 * Five foreign keys:
 *
 *     wallet_id                     → wallets.id              RESTRICT
 *     payment_id                    → wallet_payments.id      RESTRICT
 *     obligation_id                 → wallet_obligations.id   RESTRICT
 *     allocated_by                  → users.id                SET NULL
 *     allocation_policy_version_id  → versioned_policies.id   RESTRICT
 *
 * Q6 mandates RESTRICT / NO ACTION for every wallet-owned financial FK:
 * cascade deletes of financial history are forbidden. The three
 * wallet-domain FKs (`wallet_id`, `payment_id`, `obligation_id`) are
 * RESTRICT. The `allocation_policy_version_id` FK is RESTRICT, matching
 * the three policy FKs on `wallets` (2B.49) — policies are immutable-on-
 * create and cannot be deleted through any code path.
 *
 * The `allocated_by` FK is `nullOnDelete()` because the actor is metadata,
 * not an aggregate fact. Same rationale as the sibling tables (2B.50,
 * 2B.51).
 *
 * =============================================================================
 * Indexes (Step 6 v1.1.0 S6-D)
 * =============================================================================
 * The migration declares exactly the indexes classified in Step 6 v1.1.0
 * §4.2.5:
 *
 *   PRIMARY KEY      id
 *   FK-supported     wallet_id                          (implicit)
 *   FK-supported     payment_id (implicit, reuses the composite's leading
 *                               column — no standalone index declared)
 *   FK-supported     obligation_id                      (implicit)
 *   FK-supported     allocated_by                       (implicit)
 *   FK-supported     allocation_policy_version_id       (implicit)
 *   COMPOSITE        (payment_id, obligation_id)   wpa_payment_obligation_index
 *
 * Only ONE explicit `$table->index(...)` is declared — the composite
 * `(payment_id, obligation_id)`. Its leading column (`payment_id`) also
 * serves as the FK-supporting index for the `payment_id` FK, so no
 * standalone single-column index is declared for that FK.
 *
 * The `wallet_id`, `obligation_id`, `allocated_by`, and
 * `allocation_policy_version_id` FKs each have their own implicit
 * single-column index provided by MySQL when the FK constraint is
 * created. No explicit duplicate is declared.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The five §6.3 invariants. Each is enforced by AllocationService
 *     inside its transaction, using the locked parent rows.
 *
 *   - The `payment_id` and `obligation_id` same-wallet constraint. That
 *     is a service-layer check (invariant 4), structurally supported by
 *     the denormalized `wallet_id` column but not enforced by it.
 *
 *   - The `amount > 0` constraint. Enforced by the service and by the
 *     FormRequest (2B.24) at the request layer.
 *
 *   - The policy-type compatibility of `allocation_policy_version_id`.
 *     The FK proves existence only; type compatibility (that the
 *     referenced policy's `policy_type = 'allocation'`) is deferred to a
 *     future policy-attachment service (Architecture §8.6).
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `wallet_payment_allocations` table. Foreign keys target
     * the `wallets` table (2B.49), the `wallet_payments` table (2B.51),
     * the `wallet_obligations` table (2B.50), the Phase 1 `users` table,
     * and the Core `versioned_policies` table (CORE.2) — all of which
     * must already exist when this migration runs.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('wallet_payment_allocations', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Denormalized wallet scope.
            //
            // Set to the same value as `payment.wallet_id` and
            // `obligation.wallet_id` (both equal by invariant 4). Enables
            // wallet-scoped queries without traversing either parent. See
            // the class-level docblock §"The denormalized wallet_id column".
            //
            // On delete: restrict. A hard delete of the wallet must not
            // cascade into its allocations (Q6, Architecture §17.3).
            //
            // No explicit single-column index is declared; the FK's
            // implicit index is the sole index on this column.
            // -----------------------------------------------------------------
            $table->foreignId('wallet_id')
                  ->constrained('wallets')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Denormalized wallet scope (matches both parents)');

            // -----------------------------------------------------------------
            // The payment being applied.
            //
            // On delete: restrict. A hard delete of the payment must not
            // cascade into its allocations (Q6, Architecture §17.3).
            //
            // The FK's backing index is the composite (payment_id,
            // obligation_id) declared below — no separate single-column
            // index is declared. See the class-level docblock §"Indexes".
            // -----------------------------------------------------------------
            $table->foreignId('payment_id')
                  ->constrained('wallet_payments')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Payment being applied');

            // -----------------------------------------------------------------
            // The obligation being settled.
            //
            // On delete: restrict. A hard delete of the obligation must not
            // cascade into its allocations (Q6, Architecture §17.3).
            //
            // No explicit single-column index is declared; the FK's
            // implicit index is the sole index on this column.
            // -----------------------------------------------------------------
            $table->foreignId('obligation_id')
                  ->constrained('wallet_obligations')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Obligation being settled');

            // -----------------------------------------------------------------
            // Amount (Architecture §6.3).
            //
            // The POSITIVE portion of the payment being applied to the
            // obligation. Bounded above by both the payment's unapplied
            // amount and the obligation's outstanding amount at allocation
            // time (invariants 2 and 3).
            //
            // DECIMAL(20,4) matches every other monetary column in the
            // Wallet domain.
            //
            // IMMUTABLE after creation: the model's booted() guard (2B.8)
            // rejects ANY update. Corrections flow through a
            // wallet_payment_allocation_reversals row (2B.53).
            // -----------------------------------------------------------------
            $table->decimal('amount', 20, 4)
                  ->comment('Positive amount allocated; immutable after creation');

            // -----------------------------------------------------------------
            // Business time.
            //
            // The business time of the allocation. Distinct from
            // `created_at`: an administrative backfill may insert an
            // allocation now for an act that occurred earlier.
            //
            // DATETIME, not TIMESTAMP. See the class docblock §"Business
            // time immutability — allocated_at is DATETIME". The type
            // change eliminates the server-mode-dependent implicit
            // DEFAULT / ON UPDATE behavior that would otherwise rewrite
            // the business time of an allocation on any UPDATE that
            // reached the row through a path bypassing the model's guard.
            // -----------------------------------------------------------------
            $table->dateTime('allocated_at')
                  ->comment('Business time the allocation was recorded');

            // -----------------------------------------------------------------
            // Actor.
            //
            // On delete: nullify. A hard-deleted User detaches from the
            // allocation; the allocation survives with a null actor.
            //
            // Nullable at the schema level for forward compatibility with
            // a future automated (policy-driven) allocation that has no
            // human actor. The Phase 2B service requires a user on every
            // call.
            // -----------------------------------------------------------------
            $table->foreignId('allocated_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who recorded the allocation (nullable)');

            // -----------------------------------------------------------------
            // Policy-version reference (D110 seam).
            //
            // Nullable FK to the Core versioned-policy registry. When
            // non-null, records the specific policy version that governed
            // — or would have governed — this allocation. Phase 2B does
            // NOT evaluate the policy; the reference is historical
            // metadata (Architecture §10).
            //
            // On delete: restrict. Policies are immutable-on-create
            // (CORE.2, S6-C) and cannot be deleted through any code path.
            //
            // Type compatibility (that the referenced policy's
            // policy_type is 'allocation') is NOT enforced by the FK; see
            // the class-level docblock §"What this table does NOT
            // enforce".
            // -----------------------------------------------------------------
            $table->foreignId('allocation_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('VersionedPolicy reference (D110 seam); nullable');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // `updated_at` is present for Laravel-standard table shape
            // (Step 6 v1.1.0 §4.2). It will always equal `created_at` in
            // practice: the model's booted() guard (2B.8) rejects ANY
            // update attempt. See the class-level docblock §"Full
            // immutability".
            //
            // Both `created_at` and `updated_at` are nullable TIMESTAMP
            // columns and are not affected by the DATETIME correction.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // The composite (payment_id, obligation_id) supports "all
            // allocations from payment P to obligation O" queries. Its
            // leading column also serves as the FK-supporting index for
            // the `payment_id` FK — no standalone index is declared for
            // that FK.
            //
            // No other explicit index is declared. Every remaining FK
            // (`wallet_id`, `obligation_id`, `allocated_by`,
            // `allocation_policy_version_id`) receives its implicit
            // single-column index from MySQL's FK creation. Declaring
            // additional indexes would duplicate the FK's own index and
            // inflate write cost without benefit.
            //
            // See the class-level docblock §"Indexes".
            // -----------------------------------------------------------------
            $table->index(
                ['payment_id', 'obligation_id'],
                'wpa_payment_obligation_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `wallet_payment_allocation_reversals`
     * (2B.53) references this table via a foreign key, a rollback will
     * only succeed if that table has been dropped first — which is the
     * normal reverse order of `php artisan migrate:rollback`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_payment_allocations');
    }
};