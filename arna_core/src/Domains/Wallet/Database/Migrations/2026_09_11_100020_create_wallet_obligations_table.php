<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 4 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100020_create_wallet_obligations_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~20 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('occurred_at')
 *         ->comment('Business time the obligation was recorded');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 * After (amended source):
 *   $table->dateTime('occurred_at')
 *         ->comment('Business time the obligation was recorded');
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
 *   · due_at — declared ->timestamp()->nullable(). Applied as
 *     `timestamp NULL DEFAULT NULL`. Nullable TIMESTAMP columns are
 *     never given the ON UPDATE clause; no defect exists.
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
 * Create the `wallet_obligations` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * An obligation is an amount the member owes (or is owed) that is tracked
 * against a specific wallet. Each row carries exactly one economic
 * component from the closed `ObligationComponent` vocabulary (2B.3):
 *
 *     principal, interest, fee, penalty, tax, other
 *
 * The table does NOT store a payment/schedule category (`loan_installment`,
 * `subscription_payment`, `share_payment`, `invoice_payment`) — D109 places
 * that vocabulary outside the Wallet domain. A consuming domain supplies
 * its category as the opaque pair `reference_type` + `reference_id`; the
 * wallet domain stores it verbatim and never interprets it.
 *
 * The table is a SCOPED BUSINESS AGGREGATE of the Wallet domain
 * (Architecture §3.3). It belongs to exactly one wallet; the wallet
 * carries the tenant scope.
 *
 * =============================================================================
 * Projection vs. source of truth (Architecture §6.1)
 * =============================================================================
 * `original_amount` is the recorded amount at creation. It is IMMUTABLE —
 * a correction is not an edit to an existing obligation; it is a new
 * obligation plus a reversal of the ledger entry (Architecture §6.1).
 *
 * `outstanding_amount` is a PROJECTION. It is derived from the original
 * amount and the effective allocations recorded against this obligation:
 *
 *     outstanding_amount
 *         = original_amount
 *         − Σ(effective_allocated for all allocations of this obligation)
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
 * The two projection states a consumer reads:
 *
 *     outstanding_amount = original_amount   → open
 *     outstanding_amount = 0                 → settled
 *     0 < outstanding < original             → partially settled
 *
 * The `updated_at` column IS present on this table (unlike the append-only
 * ledger, 2B.54) because `outstanding_amount` legitimately changes over
 * the obligation's lifetime as allocations are recorded. `WalletEntry`
 * has no `updated_at` because it is never modified; `Obligation` has one
 * because its projection column is.
 *
 * =============================================================================
 * Immutability (2B.6 §"Immutability")
 * =============================================================================
 * Five columns are immutable after creation, enforced by the
 * `Obligation` model's `booted()` guard (2B.6):
 *
 *     wallet_id        — aggregate scope; an obligation is not re-parented.
 *     component        — the economic component is a fixed property.
 *     currency         — must equal the (immutable) wallet currency (Q8).
 *     original_amount  — the recorded amount. Corrections are new rows
 *                        plus ledger reversals.
 *     occurred_at      — the business time of recording. Historical fact.
 *
 * The following columns are mutable through the service layer and are
 * therefore NOT guarded:
 *
 *     outstanding_amount — the projection; updated by AllocationService.
 *     due_at             — a future CR may add a reschedule operation.
 *     reference_type     — descriptive metadata.
 *     reference_id       — descriptive metadata.
 *     description        — descriptive metadata.
 *     created_by         — actor metadata.
 *
 * The distinction is enforced at the model level, not by the schema. The
 * table accepts the UPDATE that the guard permits and rejects nothing on
 * its own.
 *
 * =============================================================================
 * Business time immutability — occurred_at is DATETIME (v1.0.2)
 * =============================================================================
 * `occurred_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. That would
 * contradict the column's own immutability contract (listed above among
 * the five guarded columns) and would silently rewrite the business time
 * of an obligation whenever `AllocationService` updated the
 * `outstanding_amount` projection on the same row.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by `ObligationService::record()` at insertion
 * time and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME and
 * TIMESTAMP both store a second-resolution instant interpreted in the
 * connection timezone. The model's `datetime` cast, the Obligation
 * model's `booted()` immutability guard, and every service-layer write
 * are unaffected.
 *
 * See also P2.2's companion amendments: the Shareholder domain
 * (`share_holding_entries.occurred_at`), the other five Wallet-domain
 * columns, and the Policy capability
 * (`versioned_policies.effective_from`). All eight amendments apply the
 * same DATETIME correction for the same reason.
 *
 * =============================================================================
 * Single-currency invariant (Q8, Architecture §6.3)
 * =============================================================================
 * `currency` must equal the owning wallet's currency. The wallet's
 * currency is itself immutable (A10), so this obligation's currency is
 * fixed for its lifetime — the obligation cannot drift to a different
 * currency while the wallet it belongs to stays in the original one.
 *
 * The equality is enforced at the service layer (ObligationService,
 * 2B.16), because the schema cannot express a portable cross-table
 * equality without a trigger. The column is denormalized from the wallet
 * so that:
 *
 *   - The allocation-time invariant (`payment.currency == obligation.currency
 *     == wallet.currency`, Architecture §6.3) can be checked without a
 *     join to `wallets`.
 *
 *   - A standalone read of an obligation's currency requires no join.
 *
 * =============================================================================
 * Reference pair (D109)
 * =============================================================================
 * The obligation may carry a `reference_type` + `reference_id` pair
 * identifying a business object in a consuming domain (a loan, a
 * subscription, a share issuance). The pair is opaque to this domain;
 * it is stored as supplied.
 *
 * The pair is OPTIONAL and MUST be all-or-nothing: a caller that supplies
 * one without the other receives a 422 from the FormRequest (2B.22) and a
 * `ValidationException` from the service (2B.16 §"assertReferencePairIsConsistent").
 * The schema does NOT enforce the pairing — it is a service-layer concern,
 * because a MySQL `CHECK` on a cross-column condition is expressible but
 * would couple the schema to a rule the service already enforces with a
 * clearer error.
 *
 * Both halves are capped at 64 characters, matching the phase-2A
 * convention for the analogous reference pair on `share_holding_entries`
 * (2A.40).
 *
 * =============================================================================
 * No ledger entry produced by this table
 * =============================================================================
 * Recording an obligation produces TWO rows inside one transaction:
 *
 *   1. A row in THIS table (the obligation aggregate).
 *   2. A row in `wallet_entries` (2B.54) of type `obligation_recorded`,
 *      written by `WalletService::recordEntry()` (2B.15) via
 *      `ObligationService::record()` (2B.16).
 *
 * The ledger entry is the authoritative record of the monetary movement;
 * this obligation row is the business aggregate that motivated it. The
 * two are linked by the ledger entry's `reference_type = 'Obligation'`
 * and `reference_id = obligation.id` (Architecture §5.3).
 *
 * This table carries NO foreign key to the ledger; the linkage runs the
 * other direction, from the ledger to this table, through the opaque
 * reference pair. The design mirrors the 2A separation between
 * `share_certificates` and `share_holding_entries`.
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
 * not cascade into its obligations. Since `wallets` itself is protected by
 * RESTRICT (2B.49), a wallet cannot be hard-deleted while any of its
 * children exist; the RESTRICT chain is deliberate.
 *
 * The `created_by` FK is `nullOnDelete()` because the actor is metadata,
 * not an aggregate fact. A hard-deleted user (an administrative action
 * outside the domain's normal flow) detaches from the obligation; the
 * obligation survives with a null actor. This mirrors the 2A convention
 * for `share_holding_entries.created_by` (2A.40).
 *
 * =============================================================================
 * Indexes (Step 6 v1.1.0 S6-D)
 * =============================================================================
 * The migration declares exactly the indexes classified in Step 6 v1.1.0
 * §4.2.3:
 *
 *   PRIMARY KEY      id
 *   FK-supported     wallet_id (implicit, reuses the composite's leading
 *                              column — no standalone index declared)
 *   FK-supported     created_by (implicit)
 *   COMPOSITE        (wallet_id, occurred_at)     wo_wallet_occurred_index
 *   COMPOSITE        (reference_type, reference_id) wo_reference_index
 *   PLAIN            component                    wo_component_index
 *
 * No standalone single-column index is declared on `wallet_id`. The
 * composite `(wallet_id, occurred_at)` has `wallet_id` as its leading
 * column, so MySQL reuses it for the FK's implicit index requirement.
 * Declaring an additional single-column index would duplicate work.
 *
 * The `wo_` prefix on the composite and plain indexes shortens the names
 * for readability; the FK constraint names remain the Laravel defaults.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The currency equality with the owning wallet. That is an
 *     ObligationService concern (Architecture §6.3).
 *
 *   - The all-or-nothing reference pair. That is a service-layer invariant
 *     (2B.16 §"assertReferencePairIsConsistent").
 *
 *   - The `outstanding_amount <= original_amount` and
 *     `outstanding_amount >= 0` invariants. Those are consequences of the
 *     projection derivation, maintained by AllocationService; a violation
 *     would indicate a bug in the service, not a legitimate state.
 *
 *   - The `due_at >= occurred_at` invariant (when `due_at` is non-null).
 *     A retrospective obligation with a past due date is a legitimate
 *     case (2B.16 §"What this service deliberately does NOT do"). A future
 *     CR may add the ordering if business policy requires it.
 *
 *   - The `component` value is one of the six `ObligationComponent`
 *     cases. The column is VARCHAR(16); the closed vocabulary is enforced
 *     by the model's enum cast (2B.6) and by `Rule::enum` in the
 *     FormRequest (2B.22). A MySQL ENUM would couple the schema to the
 *     enum's cases.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `wallet_obligations` table. Foreign keys target the
     * `wallets` table (2B.49) — which must already exist — and the Phase 1
     * `users` table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('wallet_obligations', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning wallet.
            //
            // On delete: restrict. A hard delete of the wallet must not
            // cascade into its obligations (Q6, Architecture §17.3). The
            // wallet itself is protected by RESTRICT, so a wallet cannot
            // be hard-deleted while any child row exists; the RESTRICT
            // chain is deliberate.
            //
            // The FK's backing index is the composite (wallet_id,
            // occurred_at) declared below — no separate single-column
            // index is declared. See the class-level docblock §"Indexes".
            // -----------------------------------------------------------------
            $table->foreignId('wallet_id')
                  ->constrained('wallets')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning wallet');

            // -----------------------------------------------------------------
            // Economic component (D109).
            //
            // One of the six ObligationComponent cases (2B.3):
            //
            //     principal, interest, fee, penalty, tax, other
            //
            // VARCHAR(16) leaves headroom above the longest current value
            // (`principal`, 9 characters).
            //
            // The column is NOT a MySQL ENUM. Adding a component is a
            // Change Request that updates the ObligationComponent enum
            // (2B.3) and, if applicable, the config vocabulary; a MySQL
            // ENUM would require a schema migration for every value change.
            //
            // The closed-vocabulary enforcement is at the model layer
            // (enum cast, 2B.6) and at the request layer (`Rule::enum`,
            // 2B.22).
            // -----------------------------------------------------------------
            $table->string('component', 16)
                  ->comment('ObligationComponent enum value (D109)');

            // -----------------------------------------------------------------
            // Currency (Q8, Architecture §6.3).
            //
            // ISO 4217 three-letter code. Must equal the owning wallet's
            // currency; the equality is enforced at the service layer
            // (ObligationService, 2B.16) because the schema cannot express
            // a portable cross-table equality.
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
            // Amounts (Architecture §6.1).
            //
            // `original_amount` is the recorded amount; IMMUTABLE after
            // creation (enforced by the Obligation model's booted() guard,
            // 2B.6).
            //
            // `outstanding_amount` is the projection; INITIALIZED to equal
            // `original_amount` at insert time and subsequently maintained
            // by AllocationService (2B.18).
            //
            // Both are DECIMAL(20,4) — matching every other monetary column
            // in the Wallet domain.
            // -----------------------------------------------------------------
            $table->decimal('original_amount', 20, 4)
                  ->comment('Recorded amount; immutable after creation');

            $table->decimal('outstanding_amount', 20, 4)
                  ->comment('Projection: original_amount minus effective allocations');

            // -----------------------------------------------------------------
            // Business time.
            //
            // `occurred_at` is the business time of the obligation. It is
            // distinct from `created_at`: an administrative backfill may
            // insert an obligation now for an event that occurred earlier.
            //
            // DATETIME, not TIMESTAMP. See the class docblock §"Business
            // time immutability — occurred_at is DATETIME". The type change
            // eliminates the server-mode-dependent implicit DEFAULT /
            // ON UPDATE behavior that would otherwise silently move the
            // business time whenever AllocationService updated the
            // `outstanding_amount` projection on the same row.
            //
            // `due_at` is the optional maturity. Null means open-ended.
            // The service does not enforce `due_at >= occurred_at` (see
            // the class-level docblock §"What this table does NOT enforce").
            // It remains a nullable TIMESTAMP — nullable TIMESTAMP
            // columns are not affected by MySQL's implicit default
            // behavior.
            // -----------------------------------------------------------------
            $table->dateTime('occurred_at')
                  ->comment('Business time the obligation was recorded');

            $table->timestamp('due_at')
                  ->nullable()
                  ->comment('Optional maturity; null for open-ended obligations');

            // -----------------------------------------------------------------
            // Reference pair (D109, opaque to this domain).
            //
            // Identifies a business object in a consuming domain (a loan, a
            // subscription, a share issuance). Optional and all-or-nothing;
            // the pairing rule is enforced at the service layer (2B.16).
            //
            // Both halves capped at 64 characters, matching the phase-2A
            // convention for the analogous reference pair on
            // share_holding_entries (2A.40).
            // -----------------------------------------------------------------
            $table->string('reference_type', 64)
                  ->nullable()
                  ->comment('Optional label of the source object (e.g. loan, invoice)');

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
            // obligation; the obligation survives with a null actor. The
            // column is nullable at the schema level because a future
            // system-generated obligation (e.g. a scheduled accrual) has no
            // human actor.
            // -----------------------------------------------------------------
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who recorded the obligation (nullable)');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // `updated_at` IS present on this table (unlike the append-only
            // ledger, 2B.54) because `outstanding_amount` legitimately
            // changes over the obligation's lifetime as allocations are
            // recorded. Both `created_at` and `updated_at` are nullable
            // TIMESTAMP columns and are not affected by the DATETIME
            // correction.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // The composite (wallet_id, occurred_at) supports the primary
            // read pattern: the obligations of one wallet, in chronological
            // order. Its leading column also serves as the FK-supporting
            // index for `wallet_id` — no standalone index is declared for
            // that FK.
            //
            // The composite (reference_type, reference_id) supports the
            // reverse lookup from a consuming-domain object to the
            // obligations that reference it.
            //
            // The plain index on `component` supports filter queries by
            // economic component.
            //
            // See the class-level docblock §"Indexes".
            // -----------------------------------------------------------------
            $table->index(
                ['wallet_id', 'occurred_at'],
                'wo_wallet_occurred_index',
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'wo_reference_index',
            );

            $table->index(
                'component',
                'wo_component_index',
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
        Schema::dropIfExists('wallet_obligations');
    }
};