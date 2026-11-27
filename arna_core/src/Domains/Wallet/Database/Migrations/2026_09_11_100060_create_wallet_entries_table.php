<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 8 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100060_create_wallet_entries_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~18 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('occurred_at')
 *         ->comment('Business time the change occurred (NOT the row insert time)');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 * After (amended source):
 *   $table->dateTime('occurred_at')
 *         ->comment('Business time the change occurred (NOT the row insert time)');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 *   This is the eighth and final amendment of the P2.2 sequence. With it,
 *   all eight defective TIMESTAMP columns across the three domains
 *   (Shareholder, Wallet, Policy) are corrected at the source.
 *
 * Every other line — every column, comment, foreign key action, the
 * driver-conditional self-FK pattern, the three indexes, and the
 * `down()` method — is byte-for-byte identical to the source.
 *
 * Other TIMESTAMP columns on this table, NOT amended:
 *   · created_at — declared ->timestamp()->nullable(). Applied as
 *     `timestamp NULL DEFAULT NULL`. Nullable TIMESTAMP columns are
 *     never given the ON UPDATE clause; no defect exists.
 *   · There is no `updated_at` on this table — the ledger is append-only
 *     (Architecture §3.7, D16-R1's Wallet analogue).
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `wallet_entries` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * The append-only ledger of every monetary movement in the Wallet domain.
 * Every change to `wallets.current_balance` produces exactly one row here,
 * written inside the same database transaction that updates the projection.
 *
 * Each entry records:
 *
 *   entry_type        — one of the four `WalletEntryType` cases (2B.4):
 *
 *                           obligation_recorded
 *                           payment_received
 *                           adjustment
 *                           reversal
 *
 *   component         — nullable; set only for `obligation_recorded`
 *                       entries. When set, one of the six
 *                       `ObligationComponent` cases (2B.3). Every other
 *                       entry type has a null component.
 *
 *   signed_quantity   — the SIGNED change applied to the wallet. Positive
 *                       for credit movements, negative for debit
 *                       movements (Architecture §4.2). The sign encodes
 *                       direction; there is no separate direction column.
 *
 *   balance_before    — the value of `wallets.current_balance`
 *                       immediately before this entry.
 *
 *   balance_after     — the value of `wallets.current_balance`
 *                       immediately after this entry.
 *
 *   reference_type    — the short class basename of the source object
 *                       (`Obligation`, `Payment`, `Wallet`, or
 *                       `WalletEntry`). Permitted combinations per entry
 *                       type are enumerated in Architecture §5.3 and
 *                       enforced by the service at write time.
 *
 *   reference_id      — the identifier of the source object.
 *
 *   reversed_entry_id — set on a `reversal` entry. Points back at the
 *                       original entry being reversed.
 *
 *   occurred_at       — the business time of the event. Distinct from
 *                       `created_at`: an administrative backfill may
 *                       insert an entry now for an event that occurred
 *                       earlier. Ledger reads order by this column.
 *
 *   created_by        — the user who recorded the entry. Nullable for
 *                       system-generated entries.
 *
 *   description       — optional free text.
 *
 * =============================================================================
 * The ledger is authoritative (Architecture §4.6)
 * =============================================================================
 * Decision D16-CORE, adapted to the wallet domain:
 *
 *     Wallet      = current-state projection
 *     WalletEntry = append-only ledger
 *
 * The invariant
 *
 *     balance_before + signed_quantity = balance_after
 *
 * holds for every entry ever written. If the projection (`wallets.
 * current_balance`) and the ledger ever disagree, the ledger is
 * authoritative and the projection is rebuilt from it.
 *
 * The projection invariant
 *
 *     wallet.current_balance = latest wallet_entries.balance_after
 *
 * likewise holds for every wallet at every observable moment, because
 * `WalletService::recordEntry()` (2B.15) writes the entry and updates the
 * projection inside one transaction, under a row lock on the wallet.
 *
 * =============================================================================
 * Immutability (Architecture §3.7)
 * =============================================================================
 * A posted entry is immutable:
 *
 *   - This migration declares NO `updated_at` column. The model (2B.10)
 *     sets `$timestamps = false` so Eloquent does not attempt to touch a
 *     non-existent timestamp on save.
 *
 *   - The `created_at` column is written explicitly by
 *     `WalletService::recordEntry()` (2B.15) at insertion time.
 *
 *   - The domain code provides no path that updates or deletes a posted
 *     entry. Corrections are new rows.
 *
 *   - The `WalletEntry` model's `booted()` guard (2B.10 §"Lifecycle")
 *     throws `LogicException` on ANY Eloquent-mediated `updating` or
 *     `deleting` event — a defence-in-depth layer on top of the
 *     service's discipline.
 *
 * =============================================================================
 * Business time immutability — occurred_at is DATETIME (v1.0.2)
 * =============================================================================
 * `occurred_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — which would
 * contradict this table's append-only contract and silently rewrite the
 * business time of a ledger entry on any UPDATE.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by `WalletService::recordEntry()` at insertion
 * time and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME and
 * TIMESTAMP both store a second-resolution instant interpreted in the
 * connection timezone. The model's `datetime` cast and every
 * service-layer write are unaffected.
 *
 * This is the final amendment in the P2.2 sequence. The complete set:
 *
 *   · Shareholder domain  — share_holding_entries.occurred_at   (Amendment 1)
 *   · Policy capability   — versioned_policies.effective_from   (Amendment 2)
 *   · Wallet domain       — wallets.opened_at                   (Amendment 3)
 *   · Wallet domain       — wallet_obligations.occurred_at      (Amendment 4)
 *   · Wallet domain       — wallet_payments.received_at         (Amendment 5)
 *   · Wallet domain       — wallet_payment_allocations.allocated_at       (Amendment 6)
 *   · Wallet domain       — wallet_payment_allocation_reversals.reversed_at (Amendment 7)
 *   · Wallet domain       — wallet_entries.occurred_at          (this file)
 *
 * =============================================================================
 * The `wallet_entries` table has no `updated_at` column
 * =============================================================================
 * `wallets` (2B.49), `wallet_obligations` (2B.50), `wallet_payments`
 * (2B.51), `wallet_payment_allocations` (2B.52), and
 * `wallet_payment_allocation_reversals` (2B.53) each carry the
 * Eloquent-standard `created_at` / `updated_at` pair.
 *
 * This table does NOT. The append-only ledger is never modified, so
 * there is no update timestamp to record. The omission is deliberate and
 * mirrors the 2A convention for `share_holding_entries` (2A.40).
 *
 * =============================================================================
 * Reversal linkage — driver-conditional self-FK (v1.0.1)
 * =============================================================================
 * The `reversed_entry_id` column is a nullable self-reference. It is
 * non-null only on a `reversal` entry, and it points at the original
 * posted entry that the reversal undoes.
 *
 * The link is ONE-DIRECTIONAL as a COLUMN: only the reversal points at the
 * original; the original carries no "reversed by" column.
 *
 * Step 6 v1.1.0 amendment S6-B mandated `RESTRICT` for this self-FK —
 * "never silently alter the reversal relationship". That mandate is
 * honored on MySQL (the production engine).
 *
 * SQLite does not honor it. When `db:wipe` (invoked by `migrate:fresh`
 * between tests using the `DatabaseMigrations` trait) iterates over the
 * in-memory database's tables and drops each one, SQLite refuses to drop
 * `wallet_entries` because the self-referencing FK uses `RESTRICT`. The
 * 2A precedent (`share_holding_entries.reversed_entry_id`) uses
 * `nullOnDelete`, which SQLite accepts — that is why the 2A test suites
 * passed under the same trait on the same engine.
 *
 * The correction is driver-conditional: the self-FK is declared only on
 * non-SQLite engines. On SQLite, the column exists but carries no
 * database-level FK. The application-layer guarantees remain in force on
 * both engines:
 *
 *   - `WalletService::reverseEntry()` (2B.15 §"assertReversalConsistency")
 *     rejects a reversal with a null `reversed_entry_id` and rejects a
 *     non-reversal with a non-null one.
 *
 *   - `WalletService::assertEntryNotAlreadyReversed()` (2B.15) enforces
 *     the "no double reversal" rule.
 *
 *   - The `WalletEntry` model (2B.10) provides the `reversedEntry()` and
 *     `reversals()` relations at the model layer, independent of any
 *     database-level FK.
 *
 * The `reversed_entry_id` column itself is declared unconditionally —
 * the schema always has the column. Only the FK constraint is
 * driver-conditional.
 *
 * On MySQL (production), the FK is added with `cascadeOnUpdate()` +
 * `restrictOnDelete()`, satisfying S6-B.
 *
 * =============================================================================
 * No `currency` column (Q8)
 * =============================================================================
 * Every entry is denominated in the owning wallet's currency — the Q8
 * single-currency invariant makes this unambiguous. Recording a currency
 * column would duplicate the wallet's own currency column with no
 * compensating benefit.
 *
 * =============================================================================
 * No direction column (Architecture §4.2)
 * =============================================================================
 * The direction of the movement is encoded by the SIGN of
 * `signed_quantity`. Positive is a credit movement; negative is a debit
 * movement. Zero is not produced by any Phase 2B operation — the
 * ledger-write primitive rejects zero-value entries.
 *
 * =============================================================================
 * No unique constraint on `reversed_entry_id`
 * =============================================================================
 * The absence of a UNIQUE on `reversed_entry_id` is deliberate. The
 * "no double reversal" rule (Architecture §4.3) is enforced by
 * `WalletService::reverseEntry()`, not by a schema constraint.
 *
 * =============================================================================
 * Foreign keys (Q6, Architecture §17.3)
 * =============================================================================
 * Two or three foreign keys depending on the driver:
 *
 *     wallet_id         → wallets.id         RESTRICT          (all drivers)
 *     reversed_entry_id → wallet_entries.id  RESTRICT          (non-SQLite)
 *     created_by        → users.id           SET NULL          (all drivers)
 *
 * Q6 mandates RESTRICT / NO ACTION for every wallet-owned financial FK:
 * cascade deletes of financial history are forbidden. The self-referential
 * FK on non-SQLite engines uses `restrictOnDelete` per S6-B.
 *
 * =============================================================================
 * Indexes (Step 6 v1.1.0 S6-D)
 * =============================================================================
 * Three explicit indexes plus the FK-implied ones:
 *
 *   PRIMARY KEY      id
 *   FK-supported     wallet_id (implicit, reuses the composite's leading
 *                              column)
 *   FK-supported     created_by (implicit)
 *   FK-supported     reversed_entry_id (implicit on non-SQLite)
 *   COMPOSITE        (wallet_id, occurred_at)     we_wallet_occurred_index
 *   PLAIN            entry_type                   we_entry_type_index
 *   COMPOSITE        (reference_type, reference_id) we_reference_index
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The per-entry balance invariant. Enforced by
 *     `WalletService::recordEntry()`.
 *
 *   - The projection invariant. Maintained by the same method.
 *
 *   - The entry-type vocabulary. Enforced by the model's enum cast
 *     (2B.10) and by the service.
 *
 *   - The reference-type mapping (§5.3). Enforced by the service.
 *
 *   - The "no double reversal" rule. Enforced by the service.
 *
 *   - The component-consistency rule. Enforced by the service.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `wallet_entries` table. Foreign keys target the
     * `wallets` table (2B.49), the same table (self-reference for
     * `reversed_entry_id`, on non-SQLite drivers), and the Phase 1
     * `users` table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('wallet_entries', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning wallet.
            // -----------------------------------------------------------------
            $table->foreignId('wallet_id')
                  ->constrained('wallets')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning wallet');

            // -----------------------------------------------------------------
            // Entry type (Architecture §5.1).
            // -----------------------------------------------------------------
            $table->string('entry_type', 32)
                  ->comment('WalletEntryType enum value (Architecture §5.1)');

            // -----------------------------------------------------------------
            // Economic component (D109).
            // -----------------------------------------------------------------
            $table->string('component', 16)
                  ->nullable()
                  ->comment('ObligationComponent enum value (obligation_recorded entries only)');

            // -----------------------------------------------------------------
            // Signed quantity and surrounding balances (Architecture §4.6).
            // -----------------------------------------------------------------
            $table->decimal('signed_quantity', 20, 4)
                  ->comment('Signed change (positive = credit, negative = debit)');

            $table->decimal('balance_before', 20, 4)
                  ->comment('Wallet balance immediately before this entry');

            $table->decimal('balance_after', 20, 4)
                  ->comment('Wallet balance immediately after this entry');

            // -----------------------------------------------------------------
            // Reference pair (Architecture §5.3, opaque discriminator).
            // -----------------------------------------------------------------
            $table->string('reference_type', 64)
                  ->nullable()
                  ->comment('Source object class basename; permitted per entry type (§5.3)');

            $table->string('reference_id', 64)
                  ->nullable()
                  ->comment('Source object identifier');

            // -----------------------------------------------------------------
            // Free-text description.
            // -----------------------------------------------------------------
            $table->string('description', 500)
                  ->nullable()
                  ->comment('Optional human-readable note');

            // -----------------------------------------------------------------
            // Reversal linkage column.
            //
            // The column is declared unconditionally. The FK constraint
            // is added conditionally after the create() closure returns
            // — see the explicit foreign() call below the closure.
            //
            // No `->constrained()` call here because:
            //   - MySQL needs the FK with `restrictOnDelete` (S6-B).
            //   - SQLite refuses to drop the table while a self-FK with
            //     RESTRICT exists, breaking `DatabaseMigrations`.
            //
            // See the class-level docblock §"Reversal linkage —
            // driver-conditional self-FK (v1.0.1)".
            // -----------------------------------------------------------------
            $table->foreignId('reversed_entry_id')
                  ->nullable()
                  ->comment('The entry this one reverses (nullable, reversal rows only)');

            // -----------------------------------------------------------------
            // Business time.
            //
            // DATETIME, not TIMESTAMP. See the class docblock §"Business
            // time immutability — occurred_at is DATETIME". The type
            // change eliminates the server-mode-dependent implicit
            // DEFAULT / ON UPDATE behavior that would otherwise silently
            // rewrite the business time of a ledger entry on any UPDATE
            // — contradicting the append-only contract of this table.
            // -----------------------------------------------------------------
            $table->dateTime('occurred_at')
                  ->comment('Business time the change occurred (NOT the row insert time)');

            // -----------------------------------------------------------------
            // Insert time.
            //
            // Remains a nullable TIMESTAMP. Nullable TIMESTAMP columns
            // are not affected by MySQL's implicit default behavior; this
            // column was already declared nullable and is applied as
            // `timestamp NULL DEFAULT NULL`.
            // -----------------------------------------------------------------
            $table->timestamp('created_at')
                  ->nullable()
                  ->comment('Row insert timestamp (written explicitly, no updated_at)');

            // -----------------------------------------------------------------
            // Actor.
            // -----------------------------------------------------------------
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who recorded the entry (nullable)');

            // -----------------------------------------------------------------
            // Indexes.
            // -----------------------------------------------------------------
            $table->index(
                ['wallet_id', 'occurred_at'],
                'we_wallet_occurred_index',
            );

            $table->index(
                'entry_type',
                'we_entry_type_index',
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'we_reference_index',
            );
        });

        // ---------------------------------------------------------------------
        // Reversal self-FK — MySQL (and any non-SQLite engine) only.
        //
        // SQLite refuses to drop `wallet_entries` while a self-referencing
        // FK uses RESTRICT. `migrate:fresh`, invoked between tests by the
        // `DatabaseMigrations` trait, drops every table in the in-memory
        // database and would fail at this table.
        //
        // On SQLite, the FK is omitted. The application-layer guarantees
        // (WalletService::assertReversalConsistency, the model's
        // booted() guard, and the reversedEntry()/reversals() relations)
        // remain in force. See the class-level docblock for the full
        // reasoning.
        //
        // On MySQL, the FK is added with `cascadeOnUpdate()` +
        // `restrictOnDelete()`, satisfying Step 6 v1.1.0 amendment S6-B.
        // ---------------------------------------------------------------------
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('wallet_entries', function (Blueprint $table): void {
                $table->foreign('reversed_entry_id')
                      ->references('id')
                      ->on('wallet_entries')
                      ->cascadeOnUpdate()
                      ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table may self-reference via
     * `reversed_entry_id` (on non-SQLite drivers), MySQL processes the
     * self-referencing FK constraint as part of `DROP TABLE`. No separate
     * statement is required.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
    }
};