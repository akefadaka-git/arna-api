<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 13 of 27
 * =============================================================================
 *
 * File:            Database/Migrations/2026_09_18_120000_create_governance_terms_table.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (migration)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `governance_terms` table was present
 *   in the schema. The `migrations` table's highest applied
 *   migration was `2026_09_18_110000_create_governance_positions_table`
 *   (G2b file 1 of 27). The Governance domain had two persistent
 *   entities — `governance_bodies` (G2a) and `governance_positions`
 *   (G2b file 1) — and no way to record the time-bounded periods of
 *   a body's operation.
 *
 *   Consequence: a future membership record (G2c) would have no way
 *   to express "this user occupied the Chairperson position on the
 *   Board during the 2026–2029 term" as distinct from "this user
 *   occupies the Chairperson position today, regardless of when that
 *   began". The term concept — the bounded period during which a
 *   body operates with a particular composition — had no structural
 *   home.
 *
 * After:
 *   One migration that creates the `governance_terms` table.
 *
 *   What the table stores:
 *
 *     · The identity of a governance term within a specific body —
 *       "Board Term 2026–2029", "Procurement Committee Term 1",
 *       "Ad-hoc Election Committee Term", etc.
 *
 *     · The term's bilingual label (`label_am` / `label_en`) — the
 *       human-readable name of the period, not a computed string.
 *
 *     · The term's effective date range (`effective_from` /
 *       `effective_until`) — the bounded period during which the
 *       term is in force.
 *
 *     · Optional notes — free-text for the term's rationale,
 *       circumstances, or any other information a reader of the
 *       term's record might need.
 *
 *     · The term's active flag, distinct from soft-deletion.
 *
 *   What the table does NOT store:
 *
 *     · Any organizational seed data. Terms are created per body
 *       once the specific term's dates are known; no seeder is
 *       delivered by G2b.
 *
 *     · Any computed "current term" state. Whether a term is
 *       currently in force is derived from its date range and the
 *       current date at read time — the model exposes an
 *       `is_current` accessor (G2b file 14 of 27) rather than a
 *       stored column. A stored `is_current` flag would require a
 *       scheduled job to maintain it and would drift out of sync
 *       with the dates.
 *
 *     · Any reference to memberships. A term is a structural
 *       property of a body; memberships (G2c) reference terms, not
 *       the reverse.
 *
 * =============================================================================
 * Schema decisions — column by column
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * id
 * -----------------------------------------------------------------------------
 * Standard auto-incrementing bigint unsigned primary key.
 *
 * -----------------------------------------------------------------------------
 * tenant_id
 * -----------------------------------------------------------------------------
 * Every Governance domain table carries `tenant_id` (master prompt
 * §6). A term belongs to a body, which belongs to a tenant — but
 * the term carries its own `tenant_id` for query efficiency and for
 * defence in depth.
 *
 * On delete: restrict.
 *
 * -----------------------------------------------------------------------------
 * governance_body_id
 * -----------------------------------------------------------------------------
 * The body this term belongs to. NOT NULL.
 *
 * On delete: cascade. A term is owned by its body; if the body is
 * hard-deleted (which the domain does not do in normal flow),
 * the term goes with it.
 *
 * -----------------------------------------------------------------------------
 * label_am / label_en
 * -----------------------------------------------------------------------------
 * Bilingual display labels, VARCHAR(150), nullable. Amharic first,
 * English second.
 *
 * Both nullable at the schema level. The service layer
 * (`GovernanceTermService`, G2b file 16 of 27) requires at least
 * one of them to be non-empty when a term is registered.
 *
 * The label is a human-entered string — "Term 2026–2029",
 * "የ2018-2021 የሥራ ዘመን", etc. It is NOT a computed string built
 * from the effective dates. Two reasons:
 *
 *   · The organizational convention for naming terms varies.
 *     Some bodies use year ranges, some use ordinal numbers
 *     ("First Term"), some use descriptive labels
 *     ("Transitional Term"). The schema cannot anticipate the
 *     convention.
 *
 *   · An organization may need to rename a term after the fact
 *     (for example, to correct an initial mislabel). If the label
 *     were computed from the dates, a date correction would
 *     silently change the label. A stored label is stable.
 *
 * The model's `display_label` accessor (G2b file 14 of 27) resolves
 * the locale-appropriate label from the two columns.
 *
 * -----------------------------------------------------------------------------
 * effective_from / effective_until
 * -----------------------------------------------------------------------------
 * Nullable `date` columns. The date range during which the term is
 * in force.
 *
 * Why `date` and not `datetime`:
 *
 *   · A term begins and ends on calendar days, not at specific
 *     instants. "The 2026–2029 Board term begins on Hamle 1, 2018
 *     E.C." is a date-level statement.
 *
 *   · `date` is not subject to MySQL's server-mode-dependent
 *     implicit-default behaviour that affects TIMESTAMP columns.
 *
 *   · The pattern matches `governance_bodies.effective_from` /
 *     `effective_until`, which use `date` for the same reason.
 *
 * Both nullable. A term whose start date is unknown (a historical
 * term that predates the system) has `effective_from = null`. A
 * term that is ongoing has `effective_until = null`.
 *
 * The service layer validates `effective_from <= effective_until`
 * when both are non-null. The schema does not enforce the ordering.
 *
 * -----------------------------------------------------------------------------
 * No overlap enforcement
 * -----------------------------------------------------------------------------
 * The schema does NOT prevent two terms of the same body from
 * overlapping in date range. This is deliberate.
 *
 * Real governance practice occasionally produces overlapping terms:
 *
 *   · A term may be established before its predecessor formally
 *     ends, for a transition period.
 *
 *   · A supplementary term may be established to cover a specific
 *     gap while the primary term continues.
 *
 *   · A retrospective term may be recorded for a period that
 *     partially overlaps a previously recorded term.
 *
 * The service layer (`GovernanceTermService`, G2b file 16) permits
 * overlapping terms. A future CR that decides the organization's
 * policy requires non-overlapping terms would add a validation to
 * the service — a service-layer rule, not a schema constraint.
 *
 * -----------------------------------------------------------------------------
 * notes
 * -----------------------------------------------------------------------------
 * Nullable VARCHAR(500). Free-text for the term's rationale,
 * circumstances, or any other information.
 *
 * Bounded at 500 characters because the column is intended for a
 * short explanatory note, not for the term's full documentation.
 * The term's label is the identity; the notes are context.
 *
 * -----------------------------------------------------------------------------
 * is_active
 * -----------------------------------------------------------------------------
 * Boolean, default `true`. Whether the term is currently active —
 * as distinct from whether it has been soft-deleted.
 *
 * A term may be deactivated when it is superseded by a
 * correction. The `is_active` flag is the preferred mechanism for
 * marking a term as no longer authoritative; soft-deletion is
 * reserved for a term that should never have existed.
 *
 * The `is_active` flag does NOT determine whether a term is
 * "current" — currentness is a function of the date range and the
 * current date. A term that is `is_active = true` but whose
 * `effective_until` is in the past is inactive in the temporal
 * sense but still active in the "authoritative record" sense. The
 * model's `is_current` accessor (G2b file 14 of 27) distinguishes
 * the two.
 *
 * -----------------------------------------------------------------------------
 * deleted_at
 * -----------------------------------------------------------------------------
 * Soft-delete timestamp, nullable. A term is never hard-deleted;
 * historical memberships may reference it.
 *
 * -----------------------------------------------------------------------------
 * created_at / updated_at
 * -----------------------------------------------------------------------------
 * Standard Eloquent timestamps.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two indexes support the query patterns this table must serve:
 *
 *   (governance_body_id, effective_from) — `gt_body_effective_index`
 *       — The list query: "list terms of a body in chronological
 *         order". The composite index allows the order to be
 *         satisfied by the index scan. Also serves the FK
 *         constraint on `governance_body_id` (leading column).
 *
 *   (tenant_id, is_active) — `gt_tenant_active_index`
 *       — The tenant-scoped active count. Also serves the FK
 *         constraint on `tenant_id` (leading column).
 *
 * No index on `effective_until`, `is_active` alone, or `deleted_at`.
 * The composite indexes above cover the relevant query patterns;
 * a query filtering on `effective_until < today` would typically
 * also filter on `governance_body_id`, and the composite index's
 * leftmost column serves that filter.
 *
 * No index on `label_am` / `label_en`. Term labels are not searched
 * by G2b's surface; a future CR that adds a label search would
 * introduce the index at that time.
 *
 * =============================================================================
 * Foreign-key actions
 * =============================================================================
 * Two foreign keys:
 *
 *   governance_body_id → governance_bodies.id  — cascadeOnDelete
 *   tenant_id → tenants.id                     — restrictOnDelete
 *
 * Both carry `cascadeOnUpdate()`, matching the platform's FK
 * convention.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_120000_create_governance_terms_table.php
 *
 * The directory already exists.
 *
 * The migration is discovered automatically once it is on disk.
 * Run via Plesk PHP Command runner (if available):
 *
 *     php artisan migrate
 *
 * Expected output: one new migration row applies:
 *
 *     2026_09_18_120000_create_governance_terms_table ... DONE
 *
 * Verify via phpMyAdmin: the `governance_terms` table appears with
 * columns:
 *
 *     id, tenant_id, governance_body_id, label_am, label_en,
 *     effective_from, effective_until, notes, is_active,
 *     deleted_at, created_at, updated_at
 *
 * Rollback (only during development) via Plesk PHP Command runner:
 *
 *     php artisan migrate:rollback --step=1
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Tables created:            1 (governance_terms)
 * Columns added:             12
 * Foreign keys added:        2 (tenant_id, governance_body_id)
 * Indexes added:             2 (both composite, plain)
 * Seeders added:             none (deferred to a future CR)
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `governance_terms` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A governance term — the time-bounded period during which a body
 * operates with a particular composition. A term is a structural
 * property of a body; memberships (G2c) reference terms.
 *
 * The table does NOT store:
 *
 *   · Memberships. A membership references a term, not the reverse.
 *   · Any organizational seed data. Terms are created per body once
 *     their dates are known.
 *   · Any computed "current term" state. Currentness is derived from
 *     the date range at read time.
 *
 * =============================================================================
 * Term scope — per body, not per body type
 * =============================================================================
 * A term belongs to a specific body INSTANCE. The Board's "Term
 * 2026–2029" and the Procurement Committee's "Term 2026–2029" are
 * distinct terms, even if their date ranges happen to coincide.
 *
 * =============================================================================
 * Overlapping terms are permitted
 * =============================================================================
 * The schema does NOT prevent two terms of the same body from
 * overlapping in date range. See the file-level change-verification
 * block §"No overlap enforcement" for the reasoning.
 *
 * =============================================================================
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('governance_terms', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Owning governance body.
            // -----------------------------------------------------------------
            $table->foreignId('governance_body_id')
                  ->constrained('governance_bodies')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Owning governance body (mandatory)');

            // -----------------------------------------------------------------
            // Bilingual labels. Amharic first, English second.
            //
            // The label is a human-entered string, not a computed
            // value. See the file-level change-verification block
            // §"label_am / label_en".
            // -----------------------------------------------------------------
            $table->string('label_am', 150)
                  ->nullable()
                  ->comment('Term label in Amharic');

            $table->string('label_en', 150)
                  ->nullable()
                  ->comment('Term label in English');

            // -----------------------------------------------------------------
            // Effective date range.
            //
            // `date`, not `datetime`. See the file-level
            // change-verification block §"effective_from /
            // effective_until".
            // -----------------------------------------------------------------
            $table->date('effective_from')
                  ->nullable()
                  ->comment('Date from which the term is in force; null for unknown start');

            $table->date('effective_until')
                  ->nullable()
                  ->comment('Date after which the term is no longer in force; null for open-ended');

            // -----------------------------------------------------------------
            // Notes — short free-text context.
            // -----------------------------------------------------------------
            $table->string('notes', 500)
                  ->nullable()
                  ->comment('Optional short free-text notes about the term');

            // -----------------------------------------------------------------
            // Active flag.
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the term is currently authoritative');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves historical references');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // (governance_body_id, effective_from) serves the list-
            // in-chronological-order query and the FK on
            // governance_body_id.
            //
            // (tenant_id, is_active) serves the tenant-scoped
            // active-count query and the FK on tenant_id.
            // -----------------------------------------------------------------
            $table->index(['governance_body_id', 'effective_from'], 'gt_body_effective_index');
            $table->index(['tenant_id', 'is_active'], 'gt_tenant_active_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_terms');
    }
};