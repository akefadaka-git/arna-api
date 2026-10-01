<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `share_classes` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * Share classes are the categories of share capital that Maale Arna Share
 * Company issues. Phase 2A recognises two classes (D3):
 *
 *   FOUNDERS  — held by the 29 founding shareholders, 145 shares total.
 *               Amharic label: መስራች.
 *
 *   STANDARD  — the default class for new shareholders.
 *               Amharic label: መደበኛ.
 *
 * The two rows are inserted by the ShareClassSeeder (CR-008), not by this
 * migration. The migration creates only the schema; the seeder populates
 * the reference data.
 *
 * =============================================================================
 * Bilingual name columns (Amharic-first)
 * =============================================================================
 * `name_am` is declared BEFORE `name_en` in the schema, mirroring the
 * `$fillable` order on the ShareClass model (2A.6) and the display
 * ordering used by the resource layer (2A.26). The order has no
 * functional effect on the database — MySQL stores columns in declaration
 * order, and neither column is indexed — but it keeps the schema
 * readable and consistent with the Amharic-first policy.
 *
 * Both name columns are nullable. At least one is required at the
 * application level (enforced by StoreShareClassRequest, 2A.18). The
 * schema does not enforce the "at least one" rule because MySQL does not
 * support a portable cross-column CHECK constraint that Laravel's
 * migration DSL can express cleanly.
 *
 * =============================================================================
 * Par value (D4) and currency (D5)
 * =============================================================================
 * `par_value` is DECIMAL(20,4). The scale of 4 is reserved for future
 * business needs (mirroring the policy in D16-X2 for share quantities);
 * the current par value is `10000.0000` per share. The column is NOT NULL
 * — a share class without a par value has no legal meaning.
 *
 * `currency` is CHAR(3) with a default of `ETB`. D5 restricts the domain
 * to ETB in Phase 2A; the schema allows any three-character code so that
 * a future multi-currency policy is a service change, not a migration
 * change. The column is NOT NULL.
 *
 * The two columns are intentionally separate. A single composed
 * "10,000 ETB" string would be an opaque value that cannot be queried or
 * formatted per locale.
 *
 * =============================================================================
 * Flags
 * =============================================================================
 * `is_active` (DEFAULT 1) — a deactivated share class remains in the
 * database for historical reporting, because ShareHolding and
 * ShareCertificate rows may reference it. Deactivation is a business
 * action, not a delete. Phase 2A does not expose an API for toggling this
 * flag; the seeder sets it to true for both classes.
 *
 * `is_treasury_eligible` (DEFAULT 0) — whether the class supports
 * treasury / buyback operations (D7). Treasury is permitted at the
 * platform level; this per-class flag allows an administrator to disable
 * it for a specific class without disabling the feature globally.
 *
 * =============================================================================
 * Code
 * =============================================================================
 * `code` is the canonical, immutable identifier of the class. It is
 * UPPERCASE, no more than 32 characters, and UNIQUE. It is the key that
 * every other table references indirectly — a holding references a class
 * by `share_class_id`, but application logic and configuration refer to
 * classes by `code` (`FOUNDERS`, `STANDARD`) so that the id can change
 * without breaking configuration.
 *
 * The set of valid codes is declared in
 * `config('shareholder.share_classes.codes')` (file 2A.1). The database
 * does not enforce membership in that list — a CHECK constraint against
 * a PHP array is not expressible — but the StoreShareClassRequest (2A.18)
 * validates against it, and the seeder (CR-008) uses only declared codes.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * The `deleted_at` column enables Laravel's SoftDeletes trait, which the
 * ShareClass model uses (2A.6). Soft deletion preserves references from
 * holdings and certificates to a class that would otherwise be
 * unrecoverable. Phase 2A does not expose a soft-delete operation
 * through the API; the column exists so the model's trait is functional
 * and so that future administrative actions have the mechanism
 * available.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * The only index is the UNIQUE on `code`, created by `->unique()`. The
 * table has at most a handful of rows in any realistic deployment, so
 * additional indexes would be overhead with no benefit.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `share_classes` table with the column set declared by
     * the ShareClass model (2A.6, F-2A6-1) and the domain's decisions
     * D3, D4, D5, and D7.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('share_classes', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Canonical code — the immutable, uppercase identifier that
            // configuration and application logic use to reference a class.
            // -----------------------------------------------------------------
            $table->string('code', 32)
                  ->unique()
                  ->comment('Canonical uppercase class code (FOUNDERS, STANDARD, ...)');

            // -----------------------------------------------------------------
            // Bilingual name columns. Amharic first, per the Amharic-first
            // policy; see the class-level docblock.
            // -----------------------------------------------------------------
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Share class name in Amharic');

            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Share class name in English');

            // -----------------------------------------------------------------
            // Monetary attributes. See the class-level docblock for the
            // rationale behind DECIMAL(20,4) and the separate currency
            // column.
            // -----------------------------------------------------------------
            $table->decimal('par_value', 20, 4)
                  ->comment('Par value per share, in the class currency');

            $table->char('currency', 3)
                  ->default('ETB')
                  ->comment('ISO 4217 currency code; ETB in Phase 2A');

            // -----------------------------------------------------------------
            // Flags.
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the class is available for new issuance and holdings');

            $table->boolean('is_treasury_eligible')
                  ->default(false)
                  ->comment('Whether the class supports treasury / buyback operations (D7)');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves historical references');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table is the reference root for
     * share_holdings and share_certificates, the migration's `down()` will
     * fail if those tables still exist and reference this one. In normal
     * rollback order (reverse of `up()`), those tables are dropped before
     * this one. If a partial rollback is performed manually, the operator
     * must drop dependent tables first.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('share_classes');
    }
};