<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `share_holdings` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A share holding is the CURRENT-STATE PROJECTION of a (shareholder, share
 * class) pair. It answers exactly one question:
 *
 *     How many shares of class X does shareholder Y currently hold?
 *
 * The answer is stored in the single column `current_quantity`.
 *
 * =============================================================================
 * The projection is not the source of truth
 * =============================================================================
 * Decision D16-CORE is unambiguous:
 *
 *     ShareHolding       = current-state projection
 *     ShareHoldingEntry  = append-only ledger
 *
 * The authoritative record of every balance change is on
 * `share_holding_entries` (2A.40). This table's `current_quantity` column
 * is a projection of that ledger — it is derived, not authoritative.
 *
 * Every change to `current_quantity` MUST be accompanied by an entry row
 * recording:
 *
 *     quantity        — the signed change that produced the new balance
 *     balance_before  — the previous value of `current_quantity`
 *     balance_after   — the new value of `current_quantity`
 *
 * Both writes occur inside one database transaction, with the holding row
 * locked `FOR UPDATE`, per D16-R6. The invariant
 *
 *     balance_before + quantity = balance_after
 *
 * holds for every entry ever written. If the projection and the ledger
 * ever disagree, the ledger is authoritative and the projection is
 * rebuilt from it.
 *
 * The ONLY permitted writer of `current_quantity` is
 * ShareHoldingService (2A.14), through its private `persistEntry()` method.
 * The model (2A.8) provides no mutation methods; the controller (2A.23)
 * does not call `save()` on the holding.
 *
 * =============================================================================
 * One row per pair
 * =============================================================================
 * The UNIQUE constraint on `(shareholder_id, share_class_id)` guarantees
 * that a shareholder has at most one holding row per share class. A
 * shareholder who holds FOUNDERS shares and STANDARD shares has two rows;
 * one who holds only FOUNDERS has one row.
 *
 * If the business later requires the same shareholder to hold the same
 * class in two distinct contexts (e.g. personally vs. as a trustee), the
 * schema would need a disambiguating column and a wider UNIQUE key — a
 * CR-worthy change. The current corporate evidence does not require it.
 *
 * =============================================================================
 * Quantity precision (D16-X2)
 * =============================================================================
 * `current_quantity` is DECIMAL(20,4). The scale of 4 reserves four
 * decimal places for future business needs — but the current business
 * rule (D6) forbids fractional shares. A share is an indivisible unit.
 *
 * The service (ShareHoldingService, 2A.14) therefore accepts only positive
 * whole-number magnitudes and rejects any fractional input. The schema
 * does not enforce the whole-number rule because DECIMAL is inherently
 * fractional; a CHECK constraint of the form `current_quantity = FLOOR(current_quantity)`
 * is expressible on MySQL 8 but would add coupling between the migration
 * and a rule the service already enforces with a clearer error. If the
 * business later permits fractional shares, the schema is unchanged; only
 * the service's validation rule is relaxed.
 *
 * =============================================================================
 * No currency (D16-R7)
 * =============================================================================
 * This table stores a QUANTITY of shares, not a monetary value. Currency
 * and per-share price, when they matter, belong to future transaction
 * objects (subscriptions, transfers, issuances) defined by their own
 * manifests. Adding a `currency` column here would conflate quantity with
 * value and would violate D16-R7.
 *
 * =============================================================================
 * No direction column (D16-X1)
 * =============================================================================
 * The direction of a balance change is encoded by the SIGN of
 * `share_holding_entries.quantity` (positive for increases, negative for
 * decreases). It is not stored on the holding itself, and it is not
 * stored on the entry as a separate column. Adding a `direction` column
 * to either table would duplicate state that is already unambiguous from
 * the sign and could drift out of agreement with it (F-2A4-1).
 *
 * =============================================================================
 * Row persistence
 * =============================================================================
 * A holding row is NEVER deleted by the domain. When the shareholder
 * transfers out their entire holding, `current_quantity` becomes
 * `0.0000` and the row persists. Deleting the row would orphan every
 * historical ledger entry that references it via `share_holding_id`.
 *
 * The `ON DELETE CASCADE` on `shareholder_id` applies only to a HARD
 * delete of the shareholder, which the domain does not perform
 * (shareholders use SoftDeletes). In that rare case, the cascade
 * removes the holding rows and, transitively, their ledger entries.
 *
 * =============================================================================
 * Concurrency (D16-R6)
 * =============================================================================
 * The service reads this row with `SELECT ... FOR UPDATE` inside its
 * transaction before every write. Two concurrent balance changes against
 * the same holding therefore serialize on the row lock, guaranteeing that
 * the `balance_before + quantity = balance_after` chain is monotonic. The
 * schema does not enforce this — the pattern is a service-layer invariant
 * — but the existence of a single row per pair is what makes the lock
 * effective.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `share_holdings` table. Foreign keys target the
     * `shareholders` (2A.38) and `share_classes` (2A.37) tables, both of
     * which must already exist when this migration runs.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('share_holdings', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning shareholder.
            //
            // On delete: cascade. A hard delete of the shareholder
            // (unusual — the domain uses SoftDeletes) removes its holdings.
            // -----------------------------------------------------------------
            $table->foreignId('shareholder_id')
                  ->constrained('shareholders')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Owning shareholder');

            // -----------------------------------------------------------------
            // Share class.
            //
            // On delete: restrict. A hard delete of a share class is
            // blocked while any holding references it. This forces an
            // explicit orchestration step rather than a silent cascade
            // through a shared classification.
            // -----------------------------------------------------------------
            $table->foreignId('share_class_id')
                  ->constrained('share_classes')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Share class denominated by this holding');

            // -----------------------------------------------------------------
            // Current quantity — the projection's single balance-bearing
            // column. See the class-level docblock for the semantics.
            //
            // DEFAULT 0.0000 is applied at the database level so that a
            // holding created by ShareHoldingService::findOrCreateHolding()
            // without an explicit quantity starts at zero. The service
            // always passes an explicit value; the default is defensive.
            // -----------------------------------------------------------------
            $table->decimal('current_quantity', 20, 4)
                  ->default(0)
                  ->comment('Current share quantity held by the shareholder for this class');

            // -----------------------------------------------------------------
            // Timestamps. No soft deletes — the row is a projection, not a
            // lifecycle object; it persists even at zero quantity.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Constraints.
            //
            // The composite UNIQUE guarantees one holding row per pair.
            // The plain INDEX on share_class_id supports "which holdings
            // belong to this class?" style queries that the composite
            // unique index does not serve efficiently (the composite's
            // leading column is shareholder_id).
            // -----------------------------------------------------------------
            $table->unique(['shareholder_id', 'share_class_id']);
            $table->index('share_class_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `share_holding_entries` (2A.40) references
     * this table via a foreign key, a rollback will only succeed if that
     * table has been dropped first — which is the normal reverse order of
     * `php artisan migrate:rollback`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('share_holdings');
    }
};