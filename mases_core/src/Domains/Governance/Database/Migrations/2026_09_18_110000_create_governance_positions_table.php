<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 1 of 27
 * =============================================================================
 *
 * File:            Database/Migrations/2026_09_18_110000_create_governance_positions_table.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (migration)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `governance_positions` table was
 *   present in the schema. The `migrations` table's highest applied
 *   migration was `2026_09_18_100000_create_governance_bodies_table`
 *   (G2a file 1 of 18). The Governance domain had one persistent
 *   entity — `governance_bodies` — and no way to record the
 *   structural slots a body defines for its membership.
 *
 *   Consequence: any future membership record (G2c) would have no
 *   way to express "this user is the Chairperson of the Board" as
 *   distinct from "this user is a Member of the Board". The
 *   position concept — chairperson, vice-chairperson, secretary,
 *   member, alternate, observer, ex-officio — had no structural
 *   home.
 *
 * After:
 *   One migration that creates the `governance_positions` table.
 *
 *   What the table stores:
 *
 *     · The identity of a governance position within a specific
 *       body — Chairperson, Secretary, Member, etc.
 *
 *     · The position's code (the canonical uppercase identifier
 *       used by configuration and application logic) and its
 *       bilingual name and description.
 *
 *     · Three semantic flags the position's definition carries:
 *       `is_voting` (does occupancy carry voting rights?),
 *       `is_officer` (is the position an officer position?), and
 *       `sort_order` (display ordering within the body).
 *
 *     · The position's active flag, distinct from soft-deletion.
 *
 *   What the table does NOT store:
 *
 *     · The term the position belongs to. A position is a slot
 *       defined by a body; the term (the period of the body's
 *       operation) is a separate entity introduced later in G2b.
 *       A membership (G2c) is what binds a user, a position, and
 *       a term together.
 *
 *     · The user who occupies the position. Occupancy is a
 *       membership fact, not a position fact. The position is a
 *       slot; who fills it is a membership.
 *
 *     · Any organizational seed data. The Chairperson /
 *       Secretary / Member positions for the Board of Directors
 *       are seeded by a future `GovernancePositionSeeder` after
 *       the Articles of Association confirm the exact position
 *       vocabulary. This migration creates the container; the
 *       container's contents are a separate, evidence-gated
 *       delivery.
 *
 * =============================================================================
 * Schema decisions — column by column
 * =============================================================================
 * Each column is documented below in the order it appears in the
 * `Schema::create` block. The reasoning for the column's existence,
 * its type, its nullability, and its foreign-key action are all
 * recorded together, because the platform treats the migration
 * docblock as the single authoritative schema reference for the
 * table.
 *
 * -----------------------------------------------------------------------------
 * id
 * -----------------------------------------------------------------------------
 * Standard auto-incrementing bigint unsigned primary key. Matches
 * every table in the platform.
 *
 * -----------------------------------------------------------------------------
 * tenant_id
 * -----------------------------------------------------------------------------
 * Every Governance domain table carries `tenant_id` (master prompt
 * §6). A position belongs to a body, which belongs to a tenant —
 * but the position carries its own `tenant_id` for query efficiency
 * and for defence in depth against a future code path that reads
 * the position without traversing to the body.
 *
 * On delete: restrict. A tenant hard-delete must not silently
 * remove its positions.
 *
 * -----------------------------------------------------------------------------
 * governance_body_id
 * -----------------------------------------------------------------------------
 * The body this position belongs to. NOT NULL — a position without
 * a body has no meaning.
 *
 * On delete: cascade. A position is owned by its body; if the body
 * is hard-deleted (which the domain does not do in normal flow —
 * bodies use soft-deletes), the position goes with it. This
 * cascade is defensive, not load-bearing.
 *
 * -----------------------------------------------------------------------------
 * code
 * -----------------------------------------------------------------------------
 * A stable, canonical, uppercase identifier for the position —
 * `CHAIRPERSON`, `SECRETARY`, `MEMBER`, `OBSERVER`, `ALTERNATE`,
 * `EX_OFFICIO`, etc. VARCHAR(32), not nullable.
 *
 * Unique within the body via the composite unique index
 * `gp_body_code_unique`. `CHAIRPERSON` on the Board and
 * `CHAIRPERSON` on the Procurement Committee are distinct rows;
 * two `CHAIRPERSON` rows on the same body are forbidden.
 *
 * The value is normalized to uppercase by the model's `code`
 * set-mutator, mirroring `GovernanceBody::code()`. The migration
 * does not enforce uppercase at the database level.
 *
 * -----------------------------------------------------------------------------
 * name_am / name_en
 * -----------------------------------------------------------------------------
 * Bilingual display names, VARCHAR(150), nullable. Amharic first,
 * English second — the platform's Amharic-first policy reflected in
 * column declaration order.
 *
 * Both nullable at the schema level. The service layer
 * (`GovernancePositionService`, G2b file 4 of 27) requires at least
 * one of them to be non-empty when a position is registered. The
 * same reasoning the `governance_bodies` migration documents for
 * the identical constraint applies here.
 *
 * -----------------------------------------------------------------------------
 * description_am / description_en
 * -----------------------------------------------------------------------------
 * Bilingual free-text descriptions, VARCHAR(500), nullable. Bounded
 * at 500 characters because the column is intended for a short
 * explanatory sentence, not for the position's full mandate or
 * duties. The full mandate of a body lives on `governance_bodies.mandate`;
 * a position's detailed duties live there too if the body's mandate
 * covers them. The description here is a one-line summary for a UI
 * list view.
 *
 * -----------------------------------------------------------------------------
 * is_voting
 * -----------------------------------------------------------------------------
 * Boolean, default `false`. Whether an occupant of this position
 * carries voting rights at meetings of the body.
 *
 * The flag is a property of the POSITION, not of the body and not
 * of the membership. A body may have both voting and non-voting
 * positions (an "observer" position has `is_voting = false`; a
 * "member" position has `is_voting = true`). A membership record
 * that references a non-voting position is a non-voting membership,
 * regardless of any other property of the user.
 *
 * G2b stores the flag but does not enforce any voting semantics.
 * Whether a meeting's quorum computation counts non-voting
 * positions; whether a resolution requires a majority of voting
 * positions only; whether a non-voting occupant may still speak —
 * these are G5 (meetings) and G8 (resolutions) concerns. G2b only
 * records the flag that those phases will consult.
 *
 * -----------------------------------------------------------------------------
 * is_officer
 * -----------------------------------------------------------------------------
 * Boolean, default `false`. Whether the position is an officer
 * position within the body.
 *
 * The term "officer" is deliberately not defined by the platform.
 * Different organizations define it differently — some count the
 * chairperson and secretary as officers; some include the treasurer
 * or the vice-chairperson; some include committee chairpersons. G2b
 * does not impose a definition. The flag is a marker that a future
 * phase or a frontend can use to distinguish, for example,
 * positions that appear in a "Body Officers" section of a
 * governance dashboard from positions that appear in an "Other
 * Members" section.
 *
 * -----------------------------------------------------------------------------
 * sort_order
 * -----------------------------------------------------------------------------
 * Unsigned small integer, default 0. Display ordering within the
 * body. Lower values appear first.
 *
 * `unsignedSmallInteger` is used (not `integer` and not `tinyint`):
 *
 *   · `unsignedSmallInteger` holds 0–65,535, which is more than
 *     enough for the positions of any realistic body. The
 *     `platform_applications` table uses `int unsigned` for
 *     `display_order`, but that table has far fewer rows and a
 *     wider type is harmless there. For positions, the narrow
 *     type is both sufficient and semantically clearer.
 *
 *   · A signed integer would permit negative values, which are not
 *     meaningful. Restricting to unsigned removes a class of
 *     erroneous input.
 *
 *   · `tinyint` (0–255) would be sufficient in practice but is a
 *     less common choice on the platform; `unsignedSmallInteger`
 *     aligns with the platform's ordering-column convention.
 *
 * Ties are permitted — two positions with the same `sort_order`
 * value are ordered arbitrarily by the database, and callers that
 * need a stable order should also `ORDER BY id`. The service layer
 * does not enforce uniqueness on `sort_order`.
 *
 * -----------------------------------------------------------------------------
 * is_active
 * -----------------------------------------------------------------------------
 * Boolean, default `true`. Whether the position is currently active
 * — as distinct from whether it has been soft-deleted.
 *
 * The two flags serve different purposes:
 *
 *   · `is_active = false` — the position is intentionally dormant.
 *     A body might deactivate a "Treasurer" position when the role
 *     is folded into another; the position's history is preserved
 *     so that memberships that referenced it remain valid.
 *
 *   · `deleted_at != null` — the position was created in error or
 *     should never have existed. Soft-deletion is an
 *     administrative-recovery mechanism.
 *
 * The distinction mirrors `governance_bodies.is_active` vs
 * `governance_bodies.deleted_at` and the same distinction the
 * Shareholder domain's `shareholders` table makes.
 *
 * -----------------------------------------------------------------------------
 * deleted_at
 * -----------------------------------------------------------------------------
 * Soft-delete timestamp, nullable, via `$table->softDeletes()`.
 *
 * A governance position is never hard-deleted. Historical
 * memberships reference the position by id; removing the row would
 * orphan those references. Soft deletion preserves the reference
 * while removing the position from default queries.
 *
 * -----------------------------------------------------------------------------
 * created_at / updated_at
 * -----------------------------------------------------------------------------
 * Eloquent's standard timestamps, maintained automatically by the
 * model.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Three indexes support the query patterns this table must serve:
 *
 *   (governance_body_id, code) UNIQUE — `gp_body_code_unique`
 *       — The natural-key lookup: "find the position with this
 *         code within this body". Also serves the FK constraint
 *         on `governance_body_id` (its leading column is the FK
 *         column).
 *
 *   (governance_body_id, sort_order) — `gp_body_sort_index`
 *       — The list query: "list positions of a body in display
 *         order". The composite index allows the sort to be
 *         satisfied by the index scan without a filesort.
 *
 *   (tenant_id, is_active) — `gp_tenant_active_index`
 *       — The tenant-scoped active count: "how many active
 *         positions exist in this tenant?". Also serves the FK
 *         constraint on `tenant_id`.
 *
 * No standalone index on `governance_body_id` or `tenant_id` is
 * declared — the composite indexes above have each FK column as
 * their leftmost column, so MySQL uses them to satisfy the FK
 * constraint and does not create duplicate implicit indexes.
 *
 * No index on `is_voting` or `is_officer`. Both are low-cardinality
 * booleans; a query that filters on them either scans a small
 * number of rows (positions of one body) or is better served by the
 * composite index on `(governance_body_id, sort_order)`.
 *
 * No index on `deleted_at`. The SoftDeletes global scope adds
 * `where deleted_at is null` to every default query, and MySQL's
 * query planner uses the composite indexes above to satisfy that
 * predicate without a dedicated index. The `shareholders` table
 * follows the same pattern.
 *
 * =============================================================================
 * Foreign-key actions — rationale
 * =============================================================================
 * Two foreign keys:
 *
 *   governance_body_id → governance_bodies.id
 *       `cascadeOnDelete()`. A position is owned by its body. If
 *       the body is hard-deleted (which the domain does not do in
 *       normal flow), the positions go with it. The cascade is a
 *       defensive posture, not a load-bearing behaviour.
 *
 *   tenant_id → tenants.id
 *       `restrictOnDelete()`. A tenant hard-delete must not
 *       silently remove its positions; an explicit orchestration
 *       step is required.
 *
 * Both foreign keys carry `cascadeOnUpdate()`, matching every
 * foreign key in the platform. The `id` columns of `tenants` and
 * `governance_bodies` are immutable in practice; the `cascadeOnUpdate`
 * is the platform's default for FK definitions and is inert unless
 * an id is ever updated (which the platform does not do).
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_110000_create_governance_positions_table.php
 *
 * The directory already exists (created for G2a file 1 of 18).
 *
 * The migration is discovered automatically once it is on disk,
 * because `GovernanceServiceProvider` (G2a file 15 of 18) already
 * calls `loadMigrationsFrom()` on the Migrations directory. No
 * provider change is needed.
 *
 * After uploading, run the migration via your Plesk environment:
 *
 *   · Plesk PHP Command runner (if available):
 *         php artisan migrate
 *
 *   · Plesk Scheduled Tasks (works on all plans):
 *         configure a one-off task with the command
 *         `php /path/to/artisan migrate`, run once, disable.
 *
 * Expected output: one new migration row applies:
 *
 *     2026_09_18_110000_create_governance_positions_table ... DONE
 *
 * Verify the table exists:
 *
 *   · Via phpMyAdmin:
 *         SELECT * FROM information_schema.TABLES
 *         WHERE TABLE_SCHEMA = 'mases'
 *           AND TABLE_NAME = 'governance_positions';
 *     → one row returned
 *
 *   · Via Plesk PHP Command runner:
 *         php artisan tinker
 *         >>> \Illuminate\Support\Facades\Schema::hasTable('governance_positions')
 *         => true
 *
 * Rollback (only during development):
 *
 *   · Via Plesk PHP Command runner:
 *         php artisan migrate:rollback --step=1
 *
 * The rollback drops the table. No data is preserved. Do not roll
 * back after any governance data has been entered.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Tables created:            1 (governance_positions)
 * Columns added:             14
 * Foreign keys added:        2 (tenant_id, governance_body_id)
 * Indexes added:             3 (1 unique, 2 plain)
 * Seeders added:             none (deferred to a future CR)
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `governance_positions` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A governance position — Chairperson, Secretary, Member, Observer,
 * Alternate, Ex-officio, or any other structural slot a body
 * defines for its membership.
 *
 * The table does NOT store:
 *
 *   · The user who occupies the position. Occupancy is a
 *     membership fact (G2c), not a position fact.
 *   · The term the position belongs to. Terms are a separate
 *     entity introduced later in G2b (files 13–24).
 *   · Any organizational seed data. The Board's standard
 *     positions are seeded by a future `GovernancePositionSeeder`
 *     after the Articles of Association confirm the exact
 *     vocabulary.
 *
 * =============================================================================
 * Position scope — per body, not per body type
 * =============================================================================
 * A position belongs to a specific body INSTANCE, not to a body
 * TYPE. The Board of Directors may define a "Treasurer" position
 * that the Procurement Committee does not; the same code
 * (`TREASURER`) may exist on two different bodies and refer to two
 * distinct positions.
 *
 * This design allows an organization to tailor a body's positions
 * to its specific mandate without introducing a separate
 * "position template" mechanism. The unique constraint is on
 * `(governance_body_id, code)`, not on `code` alone.
 *
 * =============================================================================
 * Tenancy
 * =============================================================================
 * Every position belongs to a tenant — both directly (via
 * `tenant_id`) and transitively (via its body). The direct
 * `tenant_id` is present for query efficiency and defence in
 * depth, mirroring the pattern `governance_bodies` uses for its own
 * tenant reference.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * The table includes `deleted_at`. Positions are referenced by
 * future memberships (G2c); hard deletion would orphan those
 * references. Soft deletion removes a position from default
 * queries while preserving the reference.
 *
 * Deactivation (`is_active = false`) is a separate, normal
 * governance action. It is distinct from soft-deletion, which is
 * an administrative-recovery mechanism.
 *
 * =============================================================================
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `governance_positions` table. Foreign keys target
     * the `tenants` table (restrict-on-delete) and the
     * `governance_bodies` table (cascade-on-delete).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('governance_positions', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            //
            // Every position belongs to exactly one tenant.
            // On delete: restrict.
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Owning governance body.
            //
            // On delete: cascade. A position is owned by its body; a
            // hard delete of the body removes its positions. In
            // normal operation, bodies are soft-deleted, so this
            // cascade is defensive, not load-bearing.
            // -----------------------------------------------------------------
            $table->foreignId('governance_body_id')
                  ->constrained('governance_bodies')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Owning governance body (mandatory)');

            // -----------------------------------------------------------------
            // Canonical code.
            //
            // Uppercase identifier, unique within the body. The
            // model's `code` set-mutator normalises the value on
            // write.
            // -----------------------------------------------------------------
            $table->string('code', 32)
                  ->comment('Canonical uppercase position code, unique within the body');

            // -----------------------------------------------------------------
            // Bilingual names. Amharic first, English second.
            // -----------------------------------------------------------------
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Position name in Amharic');

            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Position name in English');

            // -----------------------------------------------------------------
            // Bilingual descriptions — short explanatory sentences.
            // -----------------------------------------------------------------
            $table->string('description_am', 500)
                  ->nullable()
                  ->comment('Position description in Amharic');

            $table->string('description_en', 500)
                  ->nullable()
                  ->comment('Position description in English');

            // -----------------------------------------------------------------
            // Voting flag.
            //
            // G2b stores the flag; G5 and G8 consume it. See the
            // file-level change-verification block §"is_voting".
            // -----------------------------------------------------------------
            $table->boolean('is_voting')
                  ->default(false)
                  ->comment('Whether the position carries voting rights');

            // -----------------------------------------------------------------
            // Officer flag.
            //
            // G2b stores the flag; the definition of "officer" is
            // organizational policy. See the file-level
            // change-verification block §"is_officer".
            // -----------------------------------------------------------------
            $table->boolean('is_officer')
                  ->default(false)
                  ->comment('Whether the position is an officer position');

            // -----------------------------------------------------------------
            // Display ordering.
            //
            // Lower values appear first. Ties are permitted.
            // -----------------------------------------------------------------
            $table->unsignedSmallInteger('sort_order')
                  ->default(0)
                  ->comment('Display order within the body; lower first');

            // -----------------------------------------------------------------
            // Active flag.
            //
            // Distinct from soft-deletion. See the file-level
            // change-verification block §"is_active".
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the position is currently active');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves historical references');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // (governance_body_id, code) UNIQUE serves the natural-
            // key lookup and the FK on governance_body_id.
            //
            // (governance_body_id, sort_order) serves the "list
            // positions of a body in display order" query.
            //
            // (tenant_id, is_active) serves the tenant-scoped
            // active-count query and the FK on tenant_id.
            // -----------------------------------------------------------------
            $table->unique(['governance_body_id', 'code'], 'gp_body_code_unique');
            $table->index(['governance_body_id', 'sort_order'], 'gp_body_sort_index');
            $table->index(['tenant_id', 'is_active'], 'gp_tenant_active_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No data is preserved on rollback. Do not
     * roll back after any governance data has been entered.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_positions');
    }
};