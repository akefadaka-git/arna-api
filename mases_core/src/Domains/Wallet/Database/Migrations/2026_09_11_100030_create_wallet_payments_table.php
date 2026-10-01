<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 5 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100030_create_wallet_payments_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~22 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('received_at')
 *         ->comment('Business time the payment was received');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 *   The defect was live-active on this table: `unapplied_amount` is a
 *   projection column that `AllocationService` (2B.18) legitimately
 *   updates whenever an allocation or an allocation-reversal is recorded
 *   against the payment. Every such update would have silently rewritten
 *   `received_at` to the moment of the allocation, contradicting the
 *   column's own immutability contract (2B.7 lists `received_at` among
 *   the four guarded columns on a Payment).
 *
 * After (amended source):
 *   $table->dateTime('received_at')
 *         ->comment('Business time the payment was received');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 * Every other line — every column, comment, foreign key action, index
 * name, and the `down()` method — is byte-for-byte identical to the
 * source.
 *
 * Other TIMESTAMP columns on this table, NOT amended:
 *   · created_at, updated_at — from `$table->timestamps()`. Both applied
 *     as `timestamp NULL DEFAULT NULL`; no defect exists.
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
 * Create the `wallet_payments` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A payment is an amount received into a wallet. It records the money's
 * arrival; it does NOT record what the money is for. Attribution is
 * recorded separately on `wallet_payment_allocations` (2B.52), which links
 * each payment to specific obligations.
 *
 * The separation is deliberate (D110): a payment is recorded once, at
 * receipt, and may then be allocated (in whole or in part, possibly across
 * multiple obligations) at any later time. No automatic allocation is
 * performed on receipt.
 *
 * The table is a SCOPED BUSINESS AGGREGATE of the Wallet domain
 * (Architecture §3.4). It belongs to exactly one wallet; the wallet
 * carries the tenant scope.
 *
 * =============================================================================
 * Projection vs. source of truth (Architecture §6.2)
 * =============================================================================
 * `amount` is the recorded amount at creation. It is IMMUTABLE — a
 * correction is not an edit to an existing payment; it is a compensating
 * operation (a reversal of the ledger entry, or a new payment) per
 * Architecture §4.3.
 *
 * `unapplied_amount` is a PROJECTION. It is derived from the recorded
 * amount and the effective allocations recorded against this payment:
 *
 *     unapplied_amount
 *         = amount
 *         − Σ(effective_allocated for all allocations of this payment)
 *
 * where:
 *
 *     effective_allocated(allocation)
 *         = allocation.amount
 *         − Σ(reversal.reversed_amount for all reversals of that allocation)
 *
 * The projection is materialized in the column for query performance and
 * is updated transactionally by AllocationService (2B.18) whenever an
 * allocation or allocation-reversal is recorded. The derivation rule is
 * authoritative; the column is a cache.
 *
 * The three projection states a consumer reads:
 *
 *     unapplied_amount = amount        → unapplied (nothing allocated)
 *     unapplied_amount = 0             → fully applied
 *     0 < unapplied < amount           → partially applied
 *
 * The `updated_at` column IS present on this table (unlike the append-only
 * ledger, 2B.54) because `unapplied_amount` legitimately changes over the
 * payment's lifetime as allocations are recorded.
 *
 * =============================================================================
 * Immutability (2B.7 §"Immutability")
 * =============================================================================
 * Four columns are immutable after creation, enforced by the `Payment`
 * model's `booted()` guard (2B.7):
 *
 *     wallet_id   — aggregate scope; a payment is not re-parented.
 *     currency    — must equal the (immutable) wallet currency (Q8).
 *     amount      — the recorded amount. Corrections are new payments or
 *                   ledger-entry reversals (Architecture §4.3).
 *     received_at — the business time of receipt. Historical fact.
 *
 * The following columns are mutable through the service layer and are
 * therefore NOT guarded:
 *
 *     unapplied_amount — the projection; updated by AllocationService.
 *     reference_type   — descriptive metadata.
 *     reference_id     — descriptive metadata.
 *     description      — descriptive metadata.
 *     created_by       — actor metadata.
 *
 * The distinction is enforced at the model level, not by the schema.
 *
 * =============================================================================
 * Business time immutability — received_at is DATETIME (v1.0.2)
 * =============================================================================
 * `received_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. That would
 * contradict the column's own immutability contract (listed above among
 * the four guarded columns) and would silently rewrite the business time
 * of a payment whenever `AllocationService` updated the
 * `unapplied_amount` projection on the same row — which is the normal
 * operation of the wallet domain, not a hypothetical case.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by `PaymentService::record()` at insertion
 * time and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME and
 * TIMESTAMP both store a second-resolution instant interpreted in the
 * connection timezone. The model's `datetime` cast, the Payment model's
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
 * Contrast with `wallet_obligations` (2B.50)
 * =============================================================================
 * This table differs from the sibling obligations table in four ways,
 * each driven by a substantive difference between the two models:
 *
 *   1. No `component` column. Payments are NOT component-tagged
 *      (Architecture §3.4, D109): the economic meaning is established by
 *      the ALLOCATIONS that later settle specific obligations. The
 *      ledger entry produced by a payment therefore carries a null
 *      `component` (2B.15 §"assertComponentConsistency").
 *
 *   2. No `due_at` column. Payments are not owed; they are received.
 *
 *   3. `received_at` replaces `occurred_at`. The business timestamp of a
 *      payment is when it was received, not when some fact about it
 *      "occurred". The model (2B.7) names the column accordingly.
 *
 *   4. Projection column names differ: `amount` / `unapplied_amount`
 *      instead of `original_amount` / `outstanding_amount`. The naming
 *      reflects the payment's semantics: a payment is a pool of money
 *      that gets applied; an obligation is a fixed debt that gets
 *      settled.
 *
 * =============================================================================
 * Single-currency invariant (Q8, Architecture §6.3)
 * =============================================================================
 * `currency` must equal the owning wallet's currency. The wallet's
 * currency is itself immutable (A10), so this payment's currency is fixed
 * for its lifetime.
 *
 * The equality is enforced at the service layer (PaymentService, 2B.17),
 * because the schema cannot express a portable cross-table equality
 * without a trigger. The column is denormalized from the wallet for the
 * same reasons as the sibling obligations table (2B.50 §"Single-currency
 * invariant"): the allocation-time invariant
 * (`payment.currency == obligation.currency == wallet.currency`) can be
 * checked without a join, and a standalone read of a payment's currency
 * requires no join.
 *
 * =============================================================================
 * Overpayment / unapplied amount (D110, Architecture §6.4)
 * =============================================================================
 * A payment may exceed the total of the wallet's outstanding obligations.
 * That fact is recorded as a non-zero `unapplied_amount` on this row —
 * the explicit recording required by D110 (Architecture §6.4). No
 * "hold" or "reserve" state exists in Phase 2B; the unapplied amount is a
 * first-class, queryable value.
 *
 * A payment's `unapplied_amount` may remain positive indefinitely; it
 * decreases only when an allocation is recorded against it (2B.18) and
 * increases when an allocation is reversed (2B.18 §"reverse").
 *
 * =============================================================================
 * No ledger entry produced by this table
 * =============================================================================
 * Recording a payment produces TWO rows inside one transaction:
 *
 *   1. A row in THIS table (the payment aggregate).
 *   2. A row in `wallet_entries` (2B.54) of type `payment_received`,
 *      written by `WalletService::recordEntry()` (2B.15) via
 *      `PaymentService::record()` (2B.17).
 *
 * The ledger entry is the authoritative record of the monetary movement;
 * this payment row is the business aggregate that motivated it. The two
 * are linked by the ledger entry's `reference_type = 'Payment'` and
 * `reference_id = payment.id` (Architecture §5.3).
 *
 * This table carries NO foreign key to the ledger; the linkage runs the
 * other direction.
 *
 * =============================================================================
 * Foreign keys (Q6, Architecture §17.3)
 * =============================================================================
 * Two foreign keys, both with `restrictOnDelete()` / `nullOnDelete()`:
 *
 *     wallet_id   → wallets.id   RESTRICT
 *     created_by  → users.id     SET NULL
 *
 * The `wallet_id` FK is RESTRICT per Q6: a hard delete of the wallet must
 * not cascade into its payments.
 *
 * The `created_by` FK is `nullOnDelete()` because the actor is metadata,
 * not an aggregate fact. Same rationale as the sibling obligations table
 * (2B.50 §"Foreign keys").
 *
 * =============================================================================
 * Indexes (Step 6 v1.1.0 S6-D)
 * =============================================================================
 * The migration declares exactly the indexes classified in Step 6 v1.1.0
 * §4.2.4:
 *
 *   PRIMARY KEY      id
 *   FK-supported     wallet_id (implicit, reuses the composite's leading
 *                              column — no standalone index declared)
 *   FK-supported     created_by (implicit)
 *   COMPOSITE        (wallet_id, received_at)      wp_wallet_received_index
 *   COMPOSITE        (reference_type, reference_id) wp_reference_index
 *
 * No standalone single-column index is declared on `wallet_id`. The
 * composite `(wallet_id, received_at)` has `wallet_id` as its leading
 * column, so MySQL reuses it for the FK's implicit index requirement.
 *
 * There is no plain index on any column of this table (unlike the
 * obligations table's `component` index, 2B.50): no 2B query pattern
 * filters payments by a single non-FK column. A future CR that adds such
 * a pattern would add the corresponding index.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The currency equality with the owning wallet. That is a
 *     PaymentService concern (Architecture §6.3).
 *
 *   - The all-or-nothing reference pair. That is a service-layer invariant
 *     (2B.17 §"assertReferencePairIsConsistent").
 *
 *   - The `unapplied_amount <= amount` and `unapplied_amount >= 0`
 *     invariants. Those are consequences of the projection derivation,
 *     maintained by AllocationService; a violation would indicate a bug
 *     in the service, not a legitimate state.
 *
 *   - Any `component` or `due_at` constraint. Neither column exists on
 *     this table; see the class-level docblock §"Contrast with
 *     wallet_obligations".
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `wallet_payments` table. Foreign keys target the
     * `wallets` table (2B.49) — which must already exist — and the Phase 1
     * `users` table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('wallet_payments', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning wallet.
            //
            // On delete: restrict. A hard delete of the wallet must not
            // cascade into its payments (Q6, Architecture §17.3).
            //
            // The FK's backing index is the composite (wallet_id,
            // received_at) declared below — no separate single-column
            // index is declared. See the class-level docblock §"Indexes".
            // -----------------------------------------------------------------
            $table->foreignId('wallet_id')
                  ->constrained('wallets')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning wallet');

            // -----------------------------------------------------------------
            // Currency (Q8, Architecture §6.3).
            //
            // ISO 4217 three-letter code. Must equal the owning wallet's
            // currency; the equality is enforced at the service layer
            // (PaymentService, 2B.17).
            //
            // CHAR(3) rather than VARCHAR(3): the value is always exactly
            // three characters, and the fixed-width type expresses that
            // contract.
            //
            // See the class-level docblock §"Single-currency invariant".
            // -----------------------------------------------------------------
            $table->char('currency', 3)
                  ->comment('ISO 4217 code; must equal the owning wallet currency');

            // -----------------------------------------------------------------
            // Amounts (Architecture §6.2).
            //
            // `amount` is the recorded amount; IMMUTABLE after creation
            // (enforced by the Payment model's booted() guard, 2B.7).
            //
            // `unapplied_amount` is the projection; INITIALIZED to equal
            // `amount` at insert time and subsequently maintained by
            // AllocationService (2B.18).
            //
            // Both are DECIMAL(20,4) — matching every other monetary column
            // in the Wallet domain.
            // -----------------------------------------------------------------
            $table->decimal('amount', 20, 4)
                  ->comment('Recorded amount received; immutable after creation');

            $table->decimal('unapplied_amount', 20, 4)
                  ->comment('Projection: amount minus effective allocations');

            // -----------------------------------------------------------------
            // Business time.
            //
            // `received_at` is the business time of the payment. It is
            // distinct from `created_at`: an administrative backfill may
            // insert a payment now for a receipt that occurred earlier.
            //
            // DATETIME, not TIMESTAMP. See the class docblock §"Business
            // time immutability — received_at is DATETIME". The type change
            // eliminates the server-mode-dependent implicit DEFAULT /
            // ON UPDATE behavior that would otherwise silently move the
            // business time whenever AllocationService updated the
            // `unapplied_amount` projection on the same row.
            //
            // The composite index (wallet_id, received_at) orders this
            // column for the primary read pattern.
            //
            // There is NO `due_at` column: payments are received, not owed.
            // See the class-level docblock §"Contrast with
            // wallet_obligations".
            // -----------------------------------------------------------------
            $table->dateTime('received_at')
                  ->comment('Business time the payment was received');

            // -----------------------------------------------------------------
            // Reference pair (D109, opaque to this domain).
            //
            // Identifies a business object in a consuming domain (a
            // transfer, an invoice, a remittance). Optional and
            // all-or-nothing; the pairing rule is enforced at the service
            // layer (2B.17).
            //
            // Both halves capped at 64 characters, matching the phase-2A
            // convention for the analogous reference pair on
            // share_holding_entries (2A.40).
            // -----------------------------------------------------------------
            $table->string('reference_type', 64)
                  ->nullable()
                  ->comment('Optional label of the source object (e.g. transfer, invoice)');

            $table->string('reference_id', 64)
                  ->nullable()
                  ->comment('Optional identifier of the source object');

            // -----------------------------------------------------------------
            // Free-text description. Informational only; the reference
            // pair is the primary audit link back to the source object.
            // -----------------------------------------------------------------
            $table->string('description', 500)
                  ->nullable()
                  ->comment('Optional free-text description');

            // -----------------------------------------------------------------
            // Actor.
            //
            // On delete: nullify. A hard-deleted User detaches from the
            // payment; the payment survives with a null actor.
            // -----------------------------------------------------------------
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who recorded the payment (nullable)');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // `updated_at` IS present on this table (unlike the append-only
            // ledger, 2B.54) because `unapplied_amount` legitimately
            // changes over the payment's lifetime as allocations are
            // recorded. Both `created_at` and `updated_at` are nullable
            // TIMESTAMP columns and are not affected by the DATETIME
            // correction.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // The composite (wallet_id, received_at) supports the primary
            // read pattern: the payments of one wallet, in chronological
            // order. Its leading column also serves as the FK-supporting
            // index for `wallet_id` — no standalone index is declared for
            // that FK.
            //
            // The composite (reference_type, reference_id) supports the
            // reverse lookup from a consuming-domain object to the
            // payments that reference it.
            //
            // No plain index is declared; see the class-level docblock
            // §"Indexes".
            // -----------------------------------------------------------------
            $table->index(
                ['wallet_id', 'received_at'],
                'wp_wallet_received_index',
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'wp_reference_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `wallet_payment_allocations` (2B.52)
     * references this table via a foreign key, a rollback will only
     * succeed if that table has been dropped first — which is the normal
     * reverse order of `php artisan migrate:rollback`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_payments');
    }
};