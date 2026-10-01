<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 1 of 17
 * =============================================================================
 *
 * File:            Database/Migrations/2026_09_18_100000_create_governance_bodies_table.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (migration)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `governance_bodies` table was present
 *   in the schema. The `migrations` table's highest `batch = 1`
 *   timestamp was `2026_09_14_124134` (the sessions table). The
 *   Governance domain had no persistent storage; its only
 *   deployment artifacts were the config, the translation
 *   catalogues, the provider, the ping route, the ping controller,
 *   the three seeders, and the launcher-registry row — all
 *   functionally complete at G1, none of them schema-dependent.
 *
 * After:
 *   One migration that creates the `governance_bodies` table — the
 *   Governance domain's first persistent entity. Every subsequent
 *   G2+ entity (positions, terms, appointments, memberships,
 *   meetings, agendas, minutes, resolutions, actions, delegations,
 *   declarations, elections, document links) will reference this
 *   table directly or transitively.
 *
 *   What the table stores:
 *
 *     · The identity of a governance body within a tenant —
 *       whether that body is the General Assembly, the Board of
 *       Directors, a standing committee, a subcommittee, or an
 *       ad-hoc body created for a specific purpose.
 *
 *     · The body's structural position: its parent, and the body
 *       it reports to.
 *
 *     · The body's mandate and quorum rule, in a form that a
 *       later phase's service can validate without a schema
 *       change.
 *
 *     · The body's effective date range — because governance
 *       bodies are established and dissolved, and their history
 *       must remain queryable after dissolution.
 *
 *   What the table does NOT store:
 *
 *     · Positions, memberships, appointments, terms. Those are
 *       separate tables introduced in G2b and G2c.
 *
 *     · Meetings, agendas, minutes, resolutions, decisions,
 *       actions, delegations, declarations, elections, documents.
 *       Those are G5 through G11.
 *
 *     · Any organizational seed data. The Board of Directors is
 *       not seeded here; it will be seeded by a future
 *       `GovernanceBodySeeder` once the Articles of Association
 *       confirm the exact legal names, hierarchy, and term
 *       lengths (G0 §12 items 1–5, 14–15, 28). This migration
 *       creates the container; the container's contents are a
 *       separate, evidence-gated delivery.
 *
 * =============================================================================
 * Schema decisions — column by column
 * =============================================================================
 * Each column is documented below in the order it appears in the
 * `Schema::create` block. The reasoning for the column's
 * existence, its type, its nullability, and its foreign-key action
 * (where applicable) are all recorded together, because the
 * Shareholder domain's migration precedent treats the migration
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
 * Every Governance domain table carries `tenant_id`. Governance
 * records never cross tenant boundaries (master prompt §6). The
 * column is NOT NULL — unlike `files.user_id`, which is nullable
 * because files can be uploaded by the system in some contexts,
 * governance bodies are always owned by an explicit tenant.
 *
 * On delete: restrict. A tenant hard-delete must not silently
 * remove its governance bodies; an explicit orchestration step is
 * required. This mirrors the shareholders and loans tables'
 * treatment of `tenant_id`.
 *
 * -----------------------------------------------------------------------------
 * code
 * -----------------------------------------------------------------------------
 * A stable, canonical, uppercase identifier for the body — for
 * example `BOARD`, `GENERAL_ASSEMBLY`, `PROCUREMENT`, `CONTROL`,
 * `ELECTION_EXECUTIVE`. VARCHAR(32), not nullable.
 *
 * Unique within a tenant via the `gb_tenant_code_unique` composite
 * index. `BOARD` in tenant A and `BOARD` in tenant B are distinct
 * rows; two `BOARD` rows in the same tenant are forbidden.
 *
 * Why a code column at all:
 *
 *   · The application layer refers to specific bodies by code
 *     (`findByCode()`), the same way the Shareholder domain refers
 *     to share classes by code (`FOUNDERS`, `STANDARD`).
 *
 *   · A code survives an id change. If a future CR needs to
 *     migrate a body's id (which is unlikely, but happens during
 *     data consolidation), the code is the stable reference.
 *
 *   · The code is not the row's identity — the `id` is. But the
 *     code is the row's NATURAL KEY, and it is what appears in
 *     the seed data, the config vocabularies, and the frontend
 *     labels.
 *
 * The value is normalized to uppercase by the model's `code`
 * attribute (a set-mutator, mirroring `ShareClass::code()`). The
 * migration does not enforce uppercase at the database level — a
 * mis-cased row would be stored as-is and caught by the model on
 * read.
 *
 * -----------------------------------------------------------------------------
 * body_type
 * -----------------------------------------------------------------------------
 * VARCHAR(32), not nullable. One of the five values the
 * `Config/governance.php` `body_types` array declares:
 *
 *     assembly, board, committee, subcommittee, ad_hoc
 *
 * The value is NOT a MySQL ENUM. Adding a new body type is a
 * Change Request that adds the value to the config array and to
 * the enum; a MySQL ENUM would require a schema migration for
 * every addition, which is unnecessarily rigid for a controlled
 * vocabulary that lives in application code.
 *
 * The column is indexed as the second half of a composite index
 * `(tenant_id, body_type)`, which serves the common query "list
 * bodies of a type within a tenant".
 *
 * The `assembly` value covers the General Assembly; `board` covers
 * the Board of Directors; `committee` covers the standing
 * committees; `subcommittee` covers committees of committees;
 * `ad_hoc` covers temporary bodies created for a specific task.
 * Individual committee identities (Procurement, Control, Election
 * Executive, Audit, Risk, Credit, Ethics, Disciplinary, Tender,
 * Strategic Planning, Compliance, Investment, HR) are `code`
 * values within the `committee` type — NOT new `body_type` values.
 *
 * -----------------------------------------------------------------------------
 * name_am / name_en
 * -----------------------------------------------------------------------------
 * Bilingual display names, VARCHAR(150), nullable. Amharic first,
 * English second — the platform's Amharic-first policy reflected in
 * column declaration order (which has no functional effect on
 * MySQL storage but keeps the schema readable and consistent with
 * the Shareholder tables).
 *
 * Both nullable at the schema level. The service layer
 * (`GovernanceBodyService`, G2a file 5) requires at least one of
 * them to be non-empty when a body is registered. The schema does
 * not enforce the "at least one" rule because MySQL does not
 * support a portable cross-column CHECK constraint that Laravel's
 * migration DSL can express cleanly, and because a service-layer
 * error produces a structured 422 with per-field messages rather
 * than a database-level integrity error.
 *
 * The widths match `share_classes.name_am` / `share_classes.name_en`
 * (both VARCHAR(150)). A governance body's name runs no longer
 * than a share class's name in practice, and consistency across
 * bilingual-name columns simplifies tooling and review.
 *
 * -----------------------------------------------------------------------------
 * parent_body_id
 * -----------------------------------------------------------------------------
 * Nullable self-referencing foreign key. A body with a parent is
 * structurally subordinate to it — a committee belongs to the
 * Board, a subcommittee belongs to a committee. A body with no
 * parent is top-level (the General Assembly, or — in some
 * configurations — the Board of Directors if the organization
 * does not model the General Assembly as its parent).
 *
 * On delete: nullify. If the parent body is hard-deleted (which
 * the domain does not do — bodies are soft-deleted, and
 * deactivation uses the `is_active` flag), the child survives with
 * its parent pointer cleared. This is safer than `cascadeOnDelete`,
 * which would silently remove every descendant of a body as a
 * side effect of removing its top ancestor. The Shareholder
 * domain's `share_certificates.replaced_by_id` uses the same
 * `nullOnDelete()` treatment for a self-reference, and the same
 * reasoning applies.
 *
 * The column is indexed via `gb_parent_index` for the two query
 * patterns that use it:
 *
 *   · "Find the children of a body" (a `where parent_body_id = ?`
 *     filter).
 *   · The FK constraint itself (MySQL requires an index on a
 *     foreign-key column, satisfied here by the explicit index).
 *
 * The service layer (`GovernanceBodyService::register()` and
 * `::update()`) prevents cycles — a body may not be its own
 * ancestor. This is enforced in the service because MySQL's
 * portable migration DSL cannot express a "no cycles" constraint,
 * and because a service-layer check produces a structured error.
 *
 * -----------------------------------------------------------------------------
 * purpose
 * -----------------------------------------------------------------------------
 * Nullable VARCHAR(500). A short free-text statement of the body's
 * purpose — one sentence describing what the body does.
 *
 * Bounded at 500 characters because the column is intended for a
 * human-readable summary, not the full text of a governance
 * document. The `mandate` column (below) is the place for the
 * longer text.
 *
 * -----------------------------------------------------------------------------
 * mandate
 * -----------------------------------------------------------------------------
 * Nullable TEXT. The body's full mandate — its charter text, its
 * scope of authority, its established responsibilities. TEXT holds
 * up to 65,535 bytes on MySQL, which is sufficient for the most
 * elaborate committee charters.
 *
 * Distinct from `purpose` because the two serve different uses:
 *
 *   · `purpose` is a one-line summary that appears in list views
 *     and on the body's tile in the launcher.
 *   · `mandate` is the body's legal or organizational definition
 *     text, displayed on the body's detail page and rendered in
 *     resolutions that reference the body's authority.
 *
 * Both nullable — a body may be registered before its charter is
 * finalized.
 *
 * -----------------------------------------------------------------------------
 * quorum_rule_json
 * -----------------------------------------------------------------------------
 * Nullable JSON. The quorum rule that determines whether a meeting
 * of this body can conduct business.
 *
 * The master prompt §16 requires that quorum is "stored in a
 * structured representation such as quorum_rule_json" but does not
 * specify the JSON shape. The intended shape — documented here so
 * that G5's `MeetingService` validates against the same contract —
 * is:
 *
 *     {
 *       "type":     "fixed_number" | "percentage_of_members" | "special",
 *       "value":    <integer>,
 *       "notes_am": <string, optional>,
 *       "notes_en": <string, optional>
 *     }
 *
 *   · `type` — one of the three enumerated values. `fixed_number`
 *     means the quorum is a specific count of members;
 *     `percentage_of_members` means the quorum is a percentage of
 *     the body's total active members at the time of the meeting;
 *     `special` means the quorum is defined by an
 *     organization-specific rule that the application does not
 *     interpret — the `notes_am` / `notes_en` fields carry a
 *     human-readable explanation, and the actual determination is
 *     made by an operator who records the quorum's satisfaction
 *     manually.
 *
 *   · `value` — an integer. For `fixed_number`, the count. For
 *     `percentage_of_members`, the percentage (0–100). For
 *     `special`, optional and may be omitted.
 *
 *   · `notes_am` / `notes_en` — optional bilingual notes.
 *
 * The column is JSON, not a separate table, because:
 *
 *   · The three types are a closed vocabulary that will not grow
 *     frequently; a future CR that adds a fourth type is a config
 *     change, not a schema change.
 *
 *   · The rule is a property of one body — it has no independent
 *     lifecycle, no audit requirement beyond the body's own, and
 *     no separate query surface.
 *
 *   · The Shareholder domain's `share_classes` stores per-class
 *     configuration (par value, currency, treasury-eligibility) as
 *     scalar columns rather than a JSON blob, but that is because
 *     the values are queried and indexed. Quorum rules are read
 *     when a meeting is held, not indexed or queried in bulk, so
 *     a JSON column is the correct shape here.
 *
 * The service layer (from G5) validates the JSON's shape on write.
 * The migration itself does not enforce the shape — a JSON column
 * accepts any valid JSON.
 *
 * -----------------------------------------------------------------------------
 * meeting_frequency
 * -----------------------------------------------------------------------------
 * Nullable VARCHAR(100). Free-text meeting-frequency description —
 * for example "quarterly", "monthly", "annually", "as needed".
 *
 * Bounded at 100 characters because the field is descriptive
 * metadata, not a scheduling rule. The actual scheduling (specific
 * meeting dates, recurrence patterns, holiday adjustments) is
 * handled by G5's meeting records, not by a rule stored on the
 * body.
 *
 * The column is free-text rather than an enum because the
 * frequencies that real governance bodies use are varied and
 * organization-specific. A `committee` might meet "weekly during
 * procurement season and monthly otherwise"; capturing that
 * pattern in an enum would be a losing battle.
 *
 * -----------------------------------------------------------------------------
 * reporting_body_id
 * -----------------------------------------------------------------------------
 * Nullable self-referencing foreign key. The body this body reports
 * to. Distinct from `parent_body_id`:
 *
 *   · `parent_body_id` is a STRUCTURAL relationship — a committee
 *     is structurally part of the Board (its parent).
 *   · `reporting_body_id` is a REPORTING relationship — a committee
 *     reports its findings to the Board (its reporting target).
 *
 * In most configurations the two are the same body, but they are
 * modeled as separate columns because they can differ. A
 * cross-cutting committee might be structurally under the General
 * Assembly but report to a specific Board subcommittee. A joint
 * committee might be parented to neither body but report to both.
 *
 * On delete: nullify, for the same reason as `parent_body_id`.
 *
 * The column is indexed via `gb_reporting_index` for the FK
 * constraint and for the query "which bodies report to this one?".
 *
 * -----------------------------------------------------------------------------
 * effective_from / effective_until
 * -----------------------------------------------------------------------------
 * Nullable `date` columns (not `datetime`). The date range during
 * which the body is in force.
 *
 * Why `date` and not `datetime`:
 *
 *   · A body is established on a calendar day, not at a specific
 *     instant. "The Procurement Committee was established on
 *     Hamle 1, 2018 E.C." is a date-level statement; the time of
 *     day the establishing resolution was passed is a fact about
 *     the resolution, not about the body.
 *
 *   · The `date` type does not have MySQL's server-mode-dependent
 *     implicit-default behavior that the platform corrected
 *     platform-wide for TIMESTAMP columns (see the Shareholder
 *     domain's `share_holding_entries.occurred_at` docblock for
 *     the full rationale). A `date` column is immune to that
 *     class of defect.
 *
 *   · The Shareholder domain uses `date` for calendar-day concepts
 *     (`loan_installments.due_date`) and `datetime` for instant
 *     concepts (`versioned_policies.effective_from`). Body
 *     establishment is a calendar-day concept; `date` is the
 *     correct type.
 *
 * Both nullable. A body whose establishment date is unknown (a
 * historical import, a legacy body that predates the system) has
 * `effective_from = null`. A body that is still in force has
 * `effective_until = null`.
 *
 * The service layer validates `effective_from <= effective_until`
 * when both are non-null. The schema does not enforce the ordering
 * because MySQL's portable migration DSL cannot express it cleanly,
 * and because a service-layer error is more diagnosable.
 *
 * -----------------------------------------------------------------------------
 * is_active
 * -----------------------------------------------------------------------------
 * Boolean, default `true`. Whether the body is currently active —
 * as distinct from whether it has been soft-deleted.
 *
 * The two flags serve different purposes:
 *
 *   · `is_active = false` — the body is intentionally dormant
 *     (a committee was dissolved by resolution; a body's work is
 *     complete but the body is not deleted because its historical
 *     records still reference it). Deactivation is a normal
 *     governance action.
 *
 *   · `deleted_at != null` — the body was created in error or
 *     should never have existed. Soft-deletion is an
 *     administrative-recovery mechanism, not a governance action.
 *
 * The distinction mirrors the Shareholder domain's
 * `shareholders.is_active` (deactivation) vs `shareholders.deleted_at`
 * (soft-delete). See that migration's docblock for the full
 * reasoning.
 *
 * Indexed via `gb_active_index` for the common query "list active
 * bodies in the tenant".
 *
 * -----------------------------------------------------------------------------
 * deleted_at
 * -----------------------------------------------------------------------------
 * Soft-delete timestamp, nullable, via `$table->softDeletes()`.
 *
 * A governance body is never hard-deleted. Historical records
 * (memberships, meetings, minutes, resolutions) reference the body
 * by id; removing the row would orphan those references. Soft
 * deletion preserves the reference while removing the body from
 * default queries.
 *
 * The `GovernanceBody` model (G2a file 3) uses the `SoftDeletes`
 * trait. Default queries exclude soft-deleted rows. Administrative
 * tooling can include them explicitly with `withTrashed()`.
 *
 * -----------------------------------------------------------------------------
 * created_at / updated_at
 * -----------------------------------------------------------------------------
 * Eloquent's standard timestamps, maintained automatically by the
 * model. Included via `$table->timestamps()`.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Five indexes support the query patterns this table must serve:
 *
 *   (tenant_id, code) UNIQUE
 *       — The natural-key lookup: "find the body with this code in
 *         this tenant". Also the primary index for "list every body
 *         in this tenant, ordered by code" — the code is the
 *         conventional sort order for list views.
 *
 *   (tenant_id, body_type)
 *       — "List every board in this tenant", "list every committee
 *         in this tenant". Used by G2's list endpoint when a
 *         `?type=board` filter is applied.
 *
 *   (parent_body_id)
 *       — "Find the children of this body". Also satisfies the FK
 *         constraint on `parent_body_id`, so MySQL does not create
 *         a second, implicit index.
 *
 *   (reporting_body_id)
 *       — "Find the bodies reporting to this body". Also satisfies
 *         the FK constraint on `reporting_body_id`.
 *
 *   (is_active)
 *       — "List active bodies". Used by the launcher tile's
 *         count and by future dashboards.
 *
 * No index is declared on `name_am`, `name_en`, `purpose`,
 * `mandate`, `quorum_rule_json`, or `meeting_frequency`. No query
 * pattern in G2a filters or sorts by those columns. A future CR
 * that adds a name-search feature would introduce a full-text or
 * prefix index on `name_am` / `name_en` in a follow-up migration.
 *
 * No standalone index on `tenant_id` is declared, because the two
 * composite indexes that begin with `tenant_id` — the unique and
 * the `(tenant_id, body_type)` — already serve every tenant-scoped
 * query efficiently, and MySQL's FK constraint on `tenant_id` is
 * satisfied by the unique index's leading column.
 *
 * No standalone index on `deleted_at` is declared. The SoftDeletes
 * global scope adds `where deleted_at is null` to every default
 * query, and MySQL's query planner uses the composite indexes
 * declared above to satisfy that predicate without a dedicated
 * index on `deleted_at`. The Shareholder domain's `shareholders`
 * table follows the same pattern.
 *
 * =============================================================================
 * Self-reference and table drop
 * =============================================================================
 * The table has two self-referencing foreign keys:
 *
 *   · `parent_body_id` → `governance_bodies.id`
 *   · `reporting_body_id` → `governance_bodies.id`
 *
 * When `down()` runs `Schema::dropIfExists('governance_bodies')`,
 * MySQL processes the self-references as part of the `DROP TABLE`
 * statement. No explicit constraint-drop is required. This mirrors
 * the Shareholder domain's `share_certificates` table (which has
 * one self-reference, `replaced_by_id`) and the Loan domain's
 * `loan_restructurings` table (which has none but documents the
 * same MySQL behavior for its FK references).
 *
 * The migration is reversible. `down()` drops the table; no data
 * is preserved on rollback. Because the table is new in this
 * delivery and no historical rows exist, the first rollback loses
 * nothing. Subsequent rollbacks after real governance data is
 * entered would lose that data — the same caution that applies to
 * every MASES migration.
 *
 * =============================================================================
 * What this migration deliberately does NOT do
 * =============================================================================
 *   · It does NOT seed any row. Seeding is deferred to a future
 *     `GovernanceBodySeeder` (G2a does not deliver a seeder). The
 *     Board of Directors, the General Assembly, and any committees
 *     are organizational facts that depend on the Articles of
 *     Association; they are not coded assumptions.
 *
 *   · It does NOT enforce the `body_type` vocabulary at the
 *     database level. The five values are the application's
 *     concern; a MySQL ENUM would couple the schema to a
 *     vocabulary that lives in `Config/governance.php`.
 *
 *   · It does NOT enforce the `quorum_rule_json` shape. A JSON
 *     column accepts any valid JSON; the shape is documented in
 *     this file's docblock and validated by the service layer
 *     from G5 onward.
 *
 *   · It does NOT enforce the parent-cycle constraint. Cycle
 *     prevention is a service-layer concern
 *     (`GovernanceBodyService`, G2a file 5).
 *
 *   · It does NOT enforce the "at least one non-empty name"
 *     constraint. Name presence is a service-layer concern.
 *
 *   · It does NOT touch any other table. No existing table is
 *     altered, no column is added to an existing table, no
 *     existing index is dropped.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_100000_create_governance_bodies_table.php
 *
 * The directory `mases_core/src/Domains/Governance/Database/Migrations/`
 * must exist. If it does not, create it before uploading — the
 * file's path is the full path.
 *
 * The migration is discovered automatically once it is on disk,
 * because GovernanceServiceProvider (G1 file 4) already calls
 * `loadMigrationsFrom()` on the Migrations directory. No provider
 * change is needed to make the migration discoverable.
 *
 * Run:
 *
 *     php artisan migrate
 *
 * Expected output (approximate):
 *
 *     INFO  Running migrations.
 *
 *       2026_09_18_100000_create_governance_bodies_table ......... 45ms DONE
 *
 * Verify:
 *
 *     php artisan tinker
 *     >>> \Illuminate\Support\Facades\Schema::hasTable('governance_bodies')
 *     => true
 *     >>> \Illuminate\Support\Facades\Schema::getColumnListing('governance_bodies')
 *     => ["id", "tenant_id", "code", "body_type", "name_am", "name_en",
 *         "parent_body_id", "purpose", "mandate", "quorum_rule_json",
 *         "meeting_frequency", "reporting_body_id", "effective_from",
 *         "effective_until", "is_active", "deleted_at", "created_at",
 *         "updated_at"]
 *
 * Rollback (only if the migration needs to be re-run during
 * development):
 *
 *     php artisan migrate:rollback --step=1
 *
 * The rollback drops the table. No data is preserved. Do not
 * roll back after any governance data has been entered.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Tables created:            1 (governance_bodies)
 * Columns added:             18
 * Foreign keys added:        3 (tenant_id, parent_body_id, reporting_body_id)
 * Indexes added:             5 (1 unique, 4 plain)
 * Seeders added:             none (deferred to a future CR)
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `governance_bodies` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A governance body — the General Assembly, the Board of Directors,
 * a standing committee, a subcommittee, or an ad-hoc body — as
 * identified and configured within one tenant.
 *
 * The table does NOT store:
 *
 *   · The body's members, positions, appointments, or terms. Those
 *     live on separate tables introduced in G2b and G2c.
 *   · The body's meetings, agendas, minutes, resolutions,
 *     decisions, actions, delegations, declarations, elections, or
 *     documents. Those live on separate tables introduced in G5
 *     through G11.
 *   · Any organizational seed data. The Board, the General
 *     Assembly, and any committees are seeded by a future
 *     `GovernanceBodySeeder` after the Articles of Association
 *     confirm the exact legal names and hierarchy.
 *
 * =============================================================================
 * Configurable, not coded
 * =============================================================================
 * Individual committees — Procurement, Control, Election
 * Executive, Audit, Risk, Credit, Ethics, Disciplinary, Tender,
 * Strategic Planning, Compliance, Investment, HR — are rows with
 * `body_type = 'committee'`. There is no code branch anywhere in
 * the Governance domain that checks for a specific committee's
 * code, name, or type. This is ADR-GOV-005 made structural.
 *
 * Adding a committee is a database insert. Adding a body type is a
 * CR that extends `Config/governance.php`'s `body_types` array and
 * the `GovernanceBodyType` enum (G2a file 2). Neither requires a
 * schema change.
 *
 * =============================================================================
 * Tenancy
 * =============================================================================
 * Every row belongs to exactly one tenant via the `tenant_id`
 * column. The composite unique index `(tenant_id, code)` ensures a
 * code is unique within a tenant but may repeat across tenants.
 *
 * Multi-tenancy is architecturally supported. In the current
 * deployment, only tenant id 1 (Maale Arna Main) exists, but the
 * schema, the repository, and the service are all tenant-scoped
 * from day one.
 *
 * =============================================================================
 * Self-references
 * =============================================================================
 * The table self-references twice:
 *
 *   · `parent_body_id` — structural parent-child (a committee
 *     belongs to the Board).
 *   · `reporting_body_id` — reporting relationship (a committee
 *     reports findings to the Board).
 *
 * Both nullable, both `nullOnDelete()`, both indexed. The service
 * layer prevents parent cycles; the schema does not.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * The table includes `deleted_at` (via `$table->softDeletes()`).
 * Governance bodies are referenced by historical records — future
 * memberships, meetings, minutes, and resolutions — and hard
 * deletion would orphan those references. Soft deletion removes a
 * body from default queries while preserving the reference.
 *
 * Deactivation (`is_active = false`) is a separate, normal
 * governance action. It is distinct from soft-deletion, which is
 * an administrative-recovery mechanism. See the file-level
 * change-verification block §"is_active" for the full reasoning.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `governance_bodies` table. Foreign keys target the
     * `tenants` table (mandatory, restrict-on-delete) and the same
     * table twice (`parent_body_id`, `reporting_body_id`, both
     * nullable, null-on-delete).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('governance_bodies', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            //
            // Every governance body belongs to exactly one tenant.
            // On delete: restrict. A tenant hard-delete must not
            // silently remove its governance bodies.
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Canonical code.
            //
            // Uppercase identifier, unique within the tenant. The
            // model's `code` set-mutator normalises the value on
            // write; the migration stores whatever the model writes.
            // -----------------------------------------------------------------
            $table->string('code', 32)
                  ->comment('Canonical uppercase body code, unique within the tenant');

            // -----------------------------------------------------------------
            // Body type.
            //
            // One of: assembly, board, committee, subcommittee,
            // ad_hoc. VARCHAR, not MySQL ENUM. See the file-level
            // change-verification block §"body_type".
            // -----------------------------------------------------------------
            $table->string('body_type', 32)
                  ->comment('Body type; one of the GovernanceBodyType enum values');

            // -----------------------------------------------------------------
            // Bilingual display names. Amharic first, English
            // second. Both nullable; the service layer requires at
            // least one non-empty.
            // -----------------------------------------------------------------
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Body name in Amharic');

            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Body name in English');

            // -----------------------------------------------------------------
            // Structural parent.
            //
            // Self-referencing, nullable. On delete: nullify.
            // -----------------------------------------------------------------
            $table->foreignId('parent_body_id')
                  ->nullable()
                  ->constrained('governance_bodies')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Structural parent body; null for top-level bodies');

            // -----------------------------------------------------------------
            // Purpose — short free-text summary.
            // -----------------------------------------------------------------
            $table->string('purpose', 500)
                  ->nullable()
                  ->comment('Short free-text statement of the body\'s purpose');

            // -----------------------------------------------------------------
            // Mandate — full charter text.
            // -----------------------------------------------------------------
            $table->text('mandate')
                  ->nullable()
                  ->comment('Full mandate text (charter, scope, authority); length not bounded');

            // -----------------------------------------------------------------
            // Quorum rule — structured JSON.
            //
            // Shape documented in the file-level change-verification
            // block §"quorum_rule_json". Validated at the service
            // layer from G5 onward.
            // -----------------------------------------------------------------
            $table->json('quorum_rule_json')
                  ->nullable()
                  ->comment('Structured quorum rule; shape documented in the migration docblock');

            // -----------------------------------------------------------------
            // Meeting frequency — free-text description.
            // -----------------------------------------------------------------
            $table->string('meeting_frequency', 100)
                  ->nullable()
                  ->comment('Free-text meeting-frequency description (e.g. "quarterly")');

            // -----------------------------------------------------------------
            // Reporting body — self-referencing, nullable.
            //
            // Distinct from parent_body_id. See the file-level
            // change-verification block §"reporting_body_id".
            // -----------------------------------------------------------------
            $table->foreignId('reporting_body_id')
                  ->nullable()
                  ->constrained('governance_bodies')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Body this body reports to; null when no explicit reporting relationship');

            // -----------------------------------------------------------------
            // Effective date range.
            //
            // `date`, not `datetime`. See the file-level
            // change-verification block §"effective_from /
            // effective_until" for the reasoning.
            // -----------------------------------------------------------------
            $table->date('effective_from')
                  ->nullable()
                  ->comment('Date from which the body is in force; null for unknown establishment date');

            $table->date('effective_until')
                  ->nullable()
                  ->comment('Date after which the body is no longer in force; null for open-ended');

            // -----------------------------------------------------------------
            // Active flag.
            //
            // Distinct from soft-deletion. See the file-level
            // change-verification block §"is_active".
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the body is currently active');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves historical references');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes. See the file-level change-verification block
            // §"Indexes" for the rationale behind each.
            // -----------------------------------------------------------------
            $table->unique(['tenant_id', 'code'], 'gb_tenant_code_unique');
            $table->index(['tenant_id', 'body_type'], 'gb_tenant_type_index');
            $table->index('parent_body_id', 'gb_parent_index');
            $table->index('reporting_body_id', 'gb_reporting_index');
            $table->index('is_active', 'gb_active_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table self-references via
     * `parent_body_id` and `reporting_body_id`, MySQL processes the
     * self-referencing constraints as part of the `DROP TABLE`
     * statement. No explicit constraint-drop is required.
     *
     * No data is preserved on rollback. Do not roll back after any
     * governance data has been entered.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_bodies');
    }
};