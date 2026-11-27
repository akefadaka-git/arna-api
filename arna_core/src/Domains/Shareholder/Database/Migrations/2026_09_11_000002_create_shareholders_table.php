<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `shareholders` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A shareholder, per decision D8, is a natural person or a legally
 * recognized legal entity that lawfully owns one or more shares of Maale
 * Arna Share Company and is recorded as the holder of those shares in the
 * company's official shareholder register.
 *
 * This table stores the IDENTITY of the holder. It does NOT store:
 *
 *   - The shares they hold. Those live on `share_holdings` (current
 *     quantity) and `share_holding_entries` (ledger). A shareholder may
 *     hold zero shares if all holdings have been transferred out; the
 *     register entry persists.
 *
 *   - The certificates issued to them. Those live on `share_certificates`
 *     (2A.41).
 *
 *   - Their KYC data. The D12 field matrix is pending; when it is
 *     approved, a separate `shareholder_kyc_profiles` table will be
 *     created under its own manifest. No KYC field is present here.
 *
 * =============================================================================
 * Shareholder types (D9)
 * =============================================================================
 * `shareholder_type` stores the string value of the ShareholderType enum
 * (2A.3). Six values are permitted:
 *
 *     individual, company, trust, cooperative, government, religious_body
 *
 * The column is VARCHAR(32) — wide enough for the longest value
 * (`religious_body`, 14 characters) with headroom. It is NOT NULL: every
 * shareholder must be classified.
 *
 * The column is NOT a MySQL ENUM. Adding a shareholder type is a Change
 * Request that adds the value to the ShareholderType enum (2A.3) and to
 * the config vocabulary (2A.1). A MySQL ENUM would require a schema
 * migration for every value change, which is unnecessarily rigid for a
 * controlled vocabulary that lives in application code.
 *
 * =============================================================================
 * Bilingual name columns (Amharic-first)
 * =============================================================================
 * For individual shareholders, the name is stored in the same six-column
 * bilingual pattern as the `users` table (Phase 1):
 *
 *     first_name_am / first_name_en
 *     father_name_am / father_name_en
 *     grandfather_name_am / grandfather_name_en
 *
 * For legal-entity shareholders, the name is stored in two columns:
 *
 *     entity_name_am / entity_name_en
 *
 * In every pair, the Amharic column (`_am`) is declared BEFORE the English
 * column (`_en`). The order has no functional effect on MySQL storage,
 * but it keeps the schema consistent with:
 *
 *   - the `$fillable` order on the Shareholder model (2A.7),
 *   - the display ordering used by ShareholderResource (2A.25),
 *   - the Amharic-first policy that governs the platform.
 *
 * All eight name columns are nullable at the schema level. The service
 * layer (ShareholderService, 2A.12) and the store request
 * (StoreShareholderRequest, 2A.16) enforce that an individual supplies at
 * least one of the first four individual-name columns, and that a legal
 * entity supplies at least one of the two entity-name columns. The schema
 * does not enforce those conditions because:
 *
 *   - They are conditional on `shareholder_type`, which MySQL cannot
 *     express in a portable cross-column CHECK via Laravel's migration DSL.
 *
 *   - Enforcing them at the application layer provides clearer error
 *     messages and works identically for HTTP, Artisan, and test callers.
 *
 * =============================================================================
 * User links (D11)
 * =============================================================================
 * Two nullable columns reference `users`:
 *
 *   user_id                 — the DIRECT self-service link. Populated for
 *                             individuals whose access is granted
 *                             directly. Never populated for a legal
 *                             entity (enforced by the service).
 *
 *   representative_user_id  — the authorized REPRESENTATIVE. Mandatory
 *                             for every non-individual shareholder at the
 *                             service layer, optional for individuals.
 *
 * Both columns share the same target table (`users`). Laravel generates
 * distinct FK constraint names by column name
 * (`shareholders_user_id_foreign` and
 * `shareholders_representative_user_id_foreign`), so there is no naming
 * collision.
 *
 * A single User may represent multiple legal-entity shareholders, so
 * `representative_user_id` is NOT unique. In contrast, `user_id` is
 * constrained unique per tenant (see the UNIQUE index below): an
 * individual User links to at most one shareholder per tenant.
 *
 * =============================================================================
 * The conditional invariant is NOT a schema constraint
 * =============================================================================
 * Decision D11 implies the following domain invariant:
 *
 *     (shareholder_type = 'individual'  AND user_id IS NOT NULL)
 *       OR
 *     (shareholder_type != 'individual' AND representative_user_id IS NOT NULL)
 *
 * This migration does NOT express this invariant as a CHECK constraint.
 * MySQL 8 could express it, but:
 *
 *   - Laravel's migration DSL does not expose a clean cross-column CHECK
 *     syntax that is portable across drivers.
 *
 *   - Application-level enforcement produces structured validation errors
 *     (422 with per-field messages) rather than a database-level
 *     integrity error (500). The user experience is substantially better.
 *
 *   - The invariant is only meaningful in the context of a domain write,
 *     which ShareholderService::normaliseForRegister() and
 *     ::normaliseForUpdate() (2A.12) enforce before persist.
 *
 * If a future CR adds a data-integrity backstop at the database level, it
 * will be a follow-up migration, not an amendment to this one.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Four indexes are declared:
 *
 *   tenant_id (INDEX)                  — every query is tenant-scoped.
 *
 *   shareholder_type (INDEX)           — supports filter queries by type.
 *
 *   representative_user_id (INDEX)     — supports "which entities does
 *                                        this user represent?" lookups.
 *
 *   (tenant_id, user_id) (UNIQUE)      — one shareholder per tenant per
 *                                        linked user. MySQL treats NULL
 *                                        values as distinct in a UNIQUE
 *                                        index, so many shareholders
 *                                        with NULL user_id (all legal
 *                                        entities) coexist freely within
 *                                        a tenant.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * `deleted_at` supports Laravel's SoftDeletes trait, which the Shareholder
 * model uses (2A.7). A shareholder is never hard-deleted: historical
 * ledger entries reference the shareholder id, and removing the row would
 * orphan the ledger. Soft deletion preserves the reference while removing
 * the shareholder from active queries.
 *
 * Phase 2A does not expose a soft-delete operation through the API.
 * Deactivation is expressed via `is_active` (see F-2A12-6).
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `shareholders` table. Foreign keys target the Phase 1
     * `tenants` and `users` tables, both of which must already exist when
     * this migration runs.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('shareholders', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            //
            // Every shareholder belongs to exactly one tenant. Phase 2A
            // operates with a single main tenant; the column is present so
            // that a future multi-tenant expansion does not require a
            // schema migration.
            //
            // On delete: restrict. A tenant hard-delete must not silently
            // remove its shareholders; an explicit orchestration step is
            // required.
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Shareholder type.
            //
            // Values are the string cases of the ShareholderType enum
            // (2A.3), which mirrors decision D9:
            //
            //     individual, company, trust, cooperative, government,
            //     religious_body
            //
            // VARCHAR(32) leaves headroom above the longest current value.
            // Not NULL: every shareholder must be classified.
            // -----------------------------------------------------------------
            $table->string('shareholder_type', 32)
                  ->comment('Entity type; ShareholderType enum value (D9)');

            // -----------------------------------------------------------------
            // Individual name columns (Amharic-first).
            //
            // Populated for individual shareholders; NULL for legal
            // entities. The service layer enforces that an individual
            // supplies at least one of the first four columns.
            // -----------------------------------------------------------------
            $table->string('first_name_am', 100)
                  ->nullable()
                  ->comment('First name in Amharic');

            $table->string('first_name_en', 100)
                  ->nullable()
                  ->comment('First name in English');

            $table->string('father_name_am', 100)
                  ->nullable()
                  ->comment('Father name in Amharic');

            $table->string('father_name_en', 100)
                  ->nullable()
                  ->comment('Father name in English');

            $table->string('grandfather_name_am', 100)
                  ->nullable()
                  ->comment('Grandfather name in Amharic');

            $table->string('grandfather_name_en', 100)
                  ->nullable()
                  ->comment('Grandfather name in English');

            // -----------------------------------------------------------------
            // Legal-entity name columns (Amharic-first).
            //
            // Populated for non-individual shareholders; NULL for
            // individuals. Wider than individual name fields because entity
            // names (company registrations, cooperative names, church
            // denominations) run longer.
            // -----------------------------------------------------------------
            $table->string('entity_name_am', 200)
                  ->nullable()
                  ->comment('Legal-entity name in Amharic');

            $table->string('entity_name_en', 200)
                  ->nullable()
                  ->comment('Legal-entity name in English');

            // -----------------------------------------------------------------
            // Direct user link (D11, individual-only).
            //
            // On delete: nullify. A hard-deleted User detaches from the
            // shareholder; the shareholder survives.
            // -----------------------------------------------------------------
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Direct self-service user link (individual only)');

            // -----------------------------------------------------------------
            // Representative user link (D11, mandatory for legal entities
            // at the service layer).
            //
            // On delete: nullify. Same rationale as user_id.
            // -----------------------------------------------------------------
            $table->foreignId('representative_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Designated representative user (legal entities)');

            // -----------------------------------------------------------------
            // Active flag.
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the shareholder is currently active');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves ledger references');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // The foreign-key columns already receive an implicit index
            // from MySQL when the FK constraint is created. The explicit
            // indexes below are declared for the columns that need them
            // for non-FK query patterns, and for the composite UNIQUE.
            // -----------------------------------------------------------------
            $table->index('tenant_id');
            $table->index('shareholder_type');
            $table->index('representative_user_id');

            // One direct user link per tenant. Legal entities have NULL
            // user_id and are not constrained by this index.
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `share_holdings` (2A.39) and
     * `share_certificates` (2A.41) reference this table via foreign keys,
     * a rollback will only succeed if those tables have been dropped
     * first — which is the normal reverse order of `php artisan
     * migrate:rollback`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('shareholders');
    }
};