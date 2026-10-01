<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 3 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100010_create_wallets_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~20 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('opened_at')
 *         ->comment('When the wallet was opened (set at creation, immutable)');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 *   The wallets row IS updated in normal operation — its
 *   `current_balance` projection is written by WalletService::recordEntry()
 *   whenever a ledger entry is recorded. Every such update would have
 *   silently rewritten `opened_at` to the moment of the update,
 *   contradicting the column's own comment ("set at creation, immutable").
 *   Unlike the ledger tables (whose rows are never updated by the
 *   domain), this table's ON UPDATE clause was live-active, not
 *   theoretical.
 *
 * After (amended source):
 *   $table->dateTime('opened_at')
 *         ->comment('When the wallet was opened (set at creation, immutable)');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 * Every other line — every column, comment, foreign key action,
 * composite UNIQUE, and the `down()` method — is byte-for-byte identical
 * to the source.
 *
 * Other TIMESTAMP columns on this table, NOT amended:
 *   · closed_at — declared ->timestamp()->nullable(). Applied as
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
 * Create the `wallets` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A wallet is a member's statement-of-account ledger with Maale Arna. It
 * records obligations the member owes (or is owed), payments received, and
 * the explicit allocation of payments to obligations — under policy slots
 * whose contents are deferred (A7, A8, D110).
 *
 * This table stores the AGGREGATE ROOT of the Wallet domain: the
 * (tenant, shareholder) pair that owns the ledger, its currency, its
 * current-balance projection, its lifecycle state, and its three policy-
 * version references. It does NOT store:
 *
 *   - The ledger entries themselves. Those live on `wallet_entries`
 *     (2B.54) — the append-only source of truth.
 *
 *   - Obligations. Those live on `wallet_obligations` (2B.50).
 *
 *   - Payments. Those live on `wallet_payments` (2B.51).
 *
 *   - Allocations and their reversals. Those live on
 *     `wallet_payment_allocations` (2B.52) and
 *     `wallet_payment_allocation_reversals` (2B.53).
 *
 *   - KYC data. The A7 field matrix is not frozen; no KYC column exists
 *     on this table. When the matrix is approved, a separate
 *     `shareholder_kyc_profiles` or equivalent table will be introduced
 *     under its own manifest.
 *
 *   - A wallet identifier. D107-C places identifier concerns outside the
 *     Wallet domain; there is no `wallet_number`, `wallet_code`, or
 *     similar human-readable identifier column.
 *
 * =============================================================================
 * Single-currency invariant (Q8, A10)
 * =============================================================================
 * Every wallet is single-currency. The `currency` column is required and
 * carries an ISO 4217 three-letter code. Every obligation and every
 * payment belonging to this wallet must use the same currency; the
 * equality is enforced at the service layer (ObligationService, 2B.16;
 * PaymentService, 2B.17; AllocationService, 2B.18), because the schema
 * cannot express a portable cross-table equality without a trigger.
 *
 * The currency is IMMUTABLE for the wallet's lifetime (A10). The Wallet
 * model (2B.5) enforces this at the model level via its `booted()` guard;
 * `WalletService::open()` (2B.15) is the only writer and sets the value
 * once. Multi-currency wallets are explicitly out of scope for Phase 2B;
 * a future phase requiring them would redesign the balance model.
 *
 * =============================================================================
 * Balance projection invariant (Architecture §4.6)
 * =============================================================================
 * `current_balance` is a PROJECTION of the append-only ledger. The ledger
 * (`wallet_entries`) is authoritative; the projection is derived. Two
 * invariants hold at all times:
 *
 *     Entry invariant:
 *         balance_before + signed_quantity = balance_after
 *
 *     Projection invariant:
 *         wallets.current_balance = latest wallet_entries.balance_after
 *
 * For a wallet with no ledger entries, `current_balance = 0.0000` — the
 * default declared on the column.
 *
 * The ONLY writer of `current_balance` is `WalletService` (2B.15),
 * through its transactional write path (`recordEntry()`), which acquires
 * a row lock (`SELECT ... FOR UPDATE`) on this row for the duration of
 * the transaction. No other code path in Phase 2B updates this column.
 *
 * =============================================================================
 * Policy references (A7, A8, D110)
 * =============================================================================
 * Three nullable foreign keys reference the Core versioned-policy registry
 * (`versioned_policies`, CORE.2):
 *
 *     interest_policy_version_id      — A8 seam
 *     allocation_policy_version_id    — D110 seam
 *     kyc_policy_version_id           — A7 seam
 *
 * When null, the wallet is governed by no policy of that type. Phase 2B
 * declares the slots but does NOT evaluate them: no service reads,
 * parses, or enforces a policy payload. The slots exist so that A7, A8,
 * and D110 can attach without schema rework when their rules are
 * supplied.
 *
 * The DB-level FK proves only existence of the policy row. It does NOT
 * prove type compatibility — i.e., that `interest_policy_version_id`
 * resolves to a policy whose `policy_type = 'interest'`. Type
 * compatibility is the future policy-attachment service's concern
 * (Architecture §8.6).
 *
 * The three columns are independently nullable: a wallet may have an
 * interest policy and no allocation policy, or vice versa, or neither.
 *
 * =============================================================================
 * Lifecycle fields (Q4, Q5)
 * =============================================================================
 *   opened_at   — required. Set at creation by `WalletService::open()`
 *                 (Q4 — explicit wallet opening). Immutable: the Wallet
 *                 model's `booted()` guard (2B.5) rejects any update that
 *                 leaves this column dirty.
 *
 *   is_active   — boolean, default true. Whether the wallet is active.
 *
 *   closed_at   — nullable. Retained for a future closure workflow. Phase
 *                 2B exposes no closure or reopen operation (Q5); the
 *                 field exists so that the aggregate's shape anticipates
 *                 lifecycle state. A future CR adds the operation; no
 *                 schema change will be required.
 *
 * =============================================================================
 * Business time immutability — opened_at is DATETIME (v1.0.2)
 * =============================================================================
 * `opened_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
 *
 * The `wallets` table is the one place in this cluster where the ON
 * UPDATE clause would have been live-active: the wallets row IS updated
 * in normal operation, whenever `WalletService::recordEntry()` writes a
 * new `current_balance`. Every such update would have rewritten
 * `opened_at` to the moment of the entry — silently and permanently.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by `WalletService::open()` at wallet creation
 * and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME and
 * TIMESTAMP both store a second-resolution instant interpreted in the
 * connection timezone. The model's `datetime` cast, the Wallet model's
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
 * The composite UNIQUE (Architecture §3.2)
 * =============================================================================
 * The UNIQUE constraint on `(tenant_id, shareholder_id)` guarantees at
 * most one wallet per shareholder per tenant. A shareholder has exactly
 * one wallet in a given tenant, or none.
 *
 * The composite index also serves as the FK-supporting index for
 * `tenant_id` (its leading column). MySQL reuses the composite index for
 * the FK requirement, so no separate single-column index is declared on
 * `tenant_id`. See Step 6 v1.1.0 §4.2.2 for the index-ownership
 * classification.
 *
 * =============================================================================
 * Foreign keys (Q6, Architecture §17.3)
 * =============================================================================
 * Five foreign keys, all with `restrictOnDelete()`:
 *
 *     tenant_id      → tenants.id             RESTRICT
 *     shareholder_id → shareholders.id        RESTRICT
 *     interest_policy_version_id    → versioned_policies.id  RESTRICT
 *     allocation_policy_version_id  → versioned_policies.id  RESTRICT
 *     kyc_policy_version_id         → versioned_policies.id  RESTRICT
 *
 * Q6 mandates RESTRICT / NO ACTION for every wallet-owned financial FK:
 * cascade deletes of financial history are forbidden. The `tenant_id` and
 * `shareholder_id` FKs are hard blocks against deleting the tenant or the
 * shareholder while a wallet references them. This is a deliberate
 * divergence from 2A's `share_holdings` and `share_certificates` (which
 * use `cascadeOnDelete()` on `shareholder_id`); see Step 6 v1.1.0 §4.4
 * for the rationale — the stricter position is the correct target for a
 * financial ledger, and a future CR may align 2A to it.
 *
 * All FKs use `cascadeOnUpdate()`. Updates to primary keys are not a
 * runtime operation in Phase 2B (all primary keys are auto-incrementing
 * bigints), but the cascade declaration is present as the framework's
 * default posture, matching every FK in the 2A migrations.
 *
 * =============================================================================
 * Indexes (Step 6 v1.1.0 S6-D)
 * =============================================================================
 * The migration declares exactly the indexes classified in Step 6 v1.1.0
 * §4.2.2:
 *
 *   PRIMARY KEY      id
 *   UNIQUE           (tenant_id, shareholder_id)
 *                    → name: wallets_tenant_shareholder_unique
 *   FK-supported     tenant_id (implicit, reuses UNIQUE leading column)
 *   FK-supported     shareholder_id (implicit)
 *   FK-supported     interest_policy_version_id (implicit)
 *   FK-supported     allocation_policy_version_id (implicit)
 *   FK-supported     kyc_policy_version_id (implicit)
 *
 * No explicit single-column `$table->index(...)` is declared. Every index
 * required by a query pattern is either the composite UNIQUE above or an
 * FK-implied index. Declaring additional indexes would duplicate the
 * FK's own index and inflate write cost without benefit.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The currency equality across this row and its child obligations and
 *     payments. That is an ObligationService / PaymentService concern
 *     (Architecture §6.3).
 *
 *   - The currency format. The service validates `^[A-Z]{3}$` at write
 *     time (2B.15 §"assertCurrencyFormat"). A MySQL `CHECK` on a character
 *     class is expressible in MySQL 8 but would couple the schema to a
 *     rule the service already enforces with a clearer error.
 *
 *   - The `opened_at <= closed_at` invariant (when `closed_at` is
 *     non-null). Closure is deferred (Q5); the invariant will be enforced
 *     by the future closure service when the operation is added.
 *
 *   - The balance projection invariant (the two rules in the section
 *     above). The projection is maintained by `WalletService` under a row
 *     lock; the schema does not express the "latest entry's balance_after"
 *     relationship between two tables.
 *
 *   - The policy-type compatibility of the three policy references. That
 *     is a future policy-attachment service concern (Architecture §8.6).
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `wallets` table. Foreign keys target the Phase 1
     * `tenants` table, the 2A `shareholders` table, and the Core
     * `versioned_policies` table (CORE.2) — all of which must already
     * exist when this migration runs. The migration ordering frozen in
     * Step 6 v1.1.0 §1 guarantees that `versioned_policies` is created
     * first (filename prefix `100001` vs. this file's `100010`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            //
            // Every wallet belongs to exactly one tenant. Phase 2B operates
            // with a single main tenant; the column is present so that a
            // future multi-tenant expansion does not require a schema
            // migration.
            //
            // On delete: restrict. A tenant hard-delete must not silently
            // remove its wallets; an explicit orchestration step is required
            // (Q6, Architecture §17.3).
            //
            // The FK's backing index is the composite UNIQUE's leading
            // column — no separate single-column index is declared. See
            // the class-level docblock §"The composite UNIQUE".
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Shareholder ownership.
            //
            // The member the wallet belongs to. A shareholder has at most
            // one wallet per tenant — enforced by the composite UNIQUE
            // below.
            //
            // On delete: restrict. A hard-deleted shareholder must not
            // cascade into the wallet and its ledger; the domain uses
            // SoftDeletes (2A.7), so a hard delete is an administrative
            // purge, which the FK blocks while a wallet exists. This
            // deliberately diverges from 2A's cascade posture; see the
            // class-level docblock §"Foreign keys".
            // -----------------------------------------------------------------
            $table->foreignId('shareholder_id')
                  ->constrained('shareholders')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('The member this wallet belongs to');

            // -----------------------------------------------------------------
            // Currency.
            //
            // ISO 4217 three-letter code. Required and immutable after
            // wallet creation (A10). The single-currency invariant (Q8)
            // makes this value equal to every obligation's and every
            // payment's currency on this wallet.
            //
            // CHAR(3) rather than VARCHAR(3): the value is always exactly
            // three characters, and the fixed-width type expresses that
            // contract.
            //
            // See the class-level docblock §"Single-currency invariant"
            // for the reasoning.
            // -----------------------------------------------------------------
            $table->char('currency', 3)
                  ->comment('ISO 4217 three-letter code; immutable after open');

            // -----------------------------------------------------------------
            // Current balance — the projection of the append-only ledger.
            //
            // DECIMAL(20,4) matches every other monetary column in the
            // Wallet domain (2B.50–2B.53). The scale of 4 is a superset of
            // every currency's minor unit; the service enforces per-
            // currency minor-unit precision (2B.15 §"Currency handling").
            //
            // DEFAULT 0 is applied at the database level so that a wallet
            // created without an explicit balance starts at zero. The
            // service always passes an explicit value; the default is
            // defensive.
            //
            // See the class-level docblock §"Balance projection invariant".
            // -----------------------------------------------------------------
            $table->decimal('current_balance', 20, 4)
                  ->default(0)
                  ->comment('Wallet balance projection; maintained by WalletService');

            // -----------------------------------------------------------------
            // Policy-version references (A7, A8, D110).
            //
            // Three nullable FKs to the Core versioned-policy registry.
            // When null, the wallet is governed by no policy of that
            // type. Phase 2B declares the slots but does NOT evaluate
            // them; no service reads, parses, or enforces a policy
            // payload.
            //
            // On delete: restrict. Policies are immutable-on-create
            // (CORE.2, S6-C); they cannot be deleted through any code
            // path. The restrict is a defensive layer against a manual DB
            // edit that would otherwise silently null the reference.
            //
            // Type compatibility (that interest_policy_version_id resolves
            // to policy_type = 'interest', etc.) is NOT enforced by the
            // FK. See the class-level docblock §"Policy references".
            // -----------------------------------------------------------------
            $table->foreignId('interest_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('VersionedPolicy reference (A8 seam); nullable');

            $table->foreignId('allocation_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('VersionedPolicy reference (D110 seam); nullable');

            $table->foreignId('kyc_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('VersionedPolicy reference (A7 seam); nullable');

            // -----------------------------------------------------------------
            // Lifecycle flags.
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the wallet is active (lifecycle flag)');

            // -----------------------------------------------------------------
            // Lifecycle timestamps.
            //
            // `opened_at` is required (NOT NULL). It is set by
            // WalletService::open() at creation and is immutable
            // thereafter (enforced by the Wallet model's booted() guard,
            // 2B.5).
            //
            // DATETIME, not TIMESTAMP. See the class docblock §"Business
            // time immutability — opened_at is DATETIME". The wallets row
            // IS updated in normal operation when WalletService writes
            // the new current_balance; a TIMESTAMP column would have had
            // its value silently rewritten on every such update.
            //
            // `closed_at` is nullable. Phase 2B does not produce non-null
            // values; the field is reserved for a future closure workflow
            // (Q5). It remains a nullable TIMESTAMP — nullable TIMESTAMP
            // columns are not affected by MySQL's implicit default
            // behavior.
            // -----------------------------------------------------------------
            $table->dateTime('opened_at')
                  ->comment('When the wallet was opened (set at creation, immutable)');

            $table->timestamp('closed_at')
                  ->nullable()
                  ->comment('When the wallet was closed; null for open wallets (closure deferred, Q5)');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // `created_at` and `updated_at` are nullable TIMESTAMP columns
            // and are not affected by the DATETIME correction.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Composite uniqueness — one wallet per (tenant, shareholder).
            //
            // Declared after the FK columns for readability. The composite
            // index's leading column (`tenant_id`) also serves as the
            // backing index for the `tenant_id` FK, so MySQL will not
            // create a duplicate single-column index for that FK.
            //
            // The explicit index name is chosen to stay well below the
            // MySQL 64-character constraint and to be self-documenting.
            // See the class-level docblock §"The composite UNIQUE".
            // -----------------------------------------------------------------
            $table->unique(
                ['tenant_id', 'shareholder_id'],
                'wallets_tenant_shareholder_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because every Wallet-domain child table
     * (`wallet_obligations`, `wallet_payments`,
     * `wallet_payment_allocations`, `wallet_payment_allocation_reversals`,
     * `wallet_entries`) references this table via a foreign key, a
     * rollback will only succeed if those tables have been dropped first
     * — which is the normal reverse order of
     * `php artisan migrate:rollback`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};