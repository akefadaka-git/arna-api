<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 16 of 31
 * =============================================================================
 *
 * File:            Database/Migrations/2026_09_18_140000_create_governance_memberships_table.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (migration)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `governance_memberships` table was
 *   present in the schema. The `migrations` table's highest applied
 *   Governance migration was
 *   `2026_09_18_130000_create_governance_appointments_table` (G2c
 *   file 1 of 31). The Governance domain had four persistent
 *   entities — `governance_bodies` (G2a), `governance_positions`
 *   (G2b), `governance_terms` (G2b), and `governance_appointments`
 *   (G2c) — and no way to record the TEMPORAL OCCUPANCY that an
 *   appointment creates: which person occupied which position
 *   during which period, in which body, under which term, as a
 *   consequence of which instrument.
 *
 *   Consequence: the domain had all the STRUCTURAL entities — the
 *   body, its positions, its terms, the appointments that fill
 *   them — but no PERSON-LEVEL record. The phrase "Akefa Ayele is
 *   the Chairperson of the Board from Hamle 1, 2018 E.C." had no
 *   structural home. The membership concept — the link between a
 *   person, a position, a body, a term, and an appointment — was
 *   the missing entity.
 *
 * After:
 *   One migration that creates the `governance_memberships` table.
 *
 *   What the table stores:
 *
 *     · A single membership — the record of one person occupying
 *       one position in one body, from a start date, for a bounded
 *       or open-ended period.
 *
 *     · The person, via either a nullable `user_id` FK to Core
 *       `users` (when the member has a platform account) OR a pair
 *       of free-text name columns (`member_name_am`,
 *       `member_name_en`) when they do not. Per the G2c manifest's
 *       ruling B1, the schema is deliberately permissive on this
 *       point: the person reference is a set of columns, not a
 *       single FK.
 *
 *     · The temporal bounds — `start_date` (NOT NULL) and
 *       `end_date` (nullable for open-ended memberships).
 *
 *     · The optional link to the appointment that created the
 *       membership (`governance_appointment_id`, nullable, per
 *       ruling B2 — the relation is 1:N, an appointment creates
 *       many memberships; a membership may also have no
 *       appointment at all in legacy or direct-recording cases).
 *
 *     · The optional link to the term the membership is scoped to
 *       (`governance_term_id`, nullable for term-less memberships).
 *
 *     · A lifecycle status — one of `active`, `ended`, `suspended`,
 *       `resigned`, `removed`, `expired` per the manifest's ruling
 *       B6.
 *
 *     · The ex-officio flag (`is_ex_officio`), a per-membership
 *       marker of whether the seat is held by virtue of another
 *       office (per ruling B5).
 *
 *     · A free-text `ended_reason` — populated when the membership
 *       reaches a terminal status.
 *
 *     · Bilingual notes.
 *
 *   What the table does NOT store:
 *
 *     · Any specific document. When G11 ships, a
 *       `governance_document_link` row may attach the appointment
 *       letter or the resignation notice to the membership. This
 *       table does not itself carry files.
 *
 *     · Any attendance record. Attendance is a meeting-level fact
 *       (G5) and lives on a meeting-participants table when that
 *       phase ships.
 *
 *     · Any "current" boolean flag. Whether a membership is
 *       CURRENT is a function of its date range and the current
 *       date, computed at read time. The model exposes an
 *       `is_current` accessor rather than a stored column.
 *
 *     · Any organizational seed data.
 *
 * =============================================================================
 * Ruling B1 — the person-reference model, in full
 * =============================================================================
 * The manifest's ruling B1 was recorded as:
 *
 *     "Store free-text member_name_am, member_name_en,
 *      external_reference on `GovernanceMembership`, with a nullable
 *      user_id FK to Core users when the member is also a platform
 *      user. This avoids inventing a Persons table, keeps the
 *      domain self-contained, and lets G13 (ARNA integration) later
 *      map to a canonical person."
 *
 * The mases.sql dump confirms there is no `persons` table. The
 * Shareholder domain uses `user_id` + `representative_user_id` to
 * Core `users` — the same pattern this table follows. A future CR
 * that introduces a Core Persons entity would add a `person_id` FK
 * alongside the existing columns, migrating historical rows; this
 * migration does not anticipate that CR.
 *
 * The four columns that together identify the member:
 *
 *     user_id              — the platform account, when one exists.
 *     member_name_am       — the Amharic name for a non-user member.
 *     member_name_en       — the English name for a non-user member.
 *     external_reference   — an external identifier (an employee
 *                            number, a shareholder record number,
 *                            a pension ID).
 *
 * The service layer (`GovernanceMembershipService`, G2c file 20 of
 * 31) requires at least one identifying column to be present —
 * either `user_id` or at least one non-empty name. A membership
 * with no identifying reference is refused with a 422. The schema
 * itself is permissive because MySQL's portable migration DSL
 * cannot express a cross-column "at least one of these four" CHECK
 * cleanly, and because a service-layer error produces a structured
 * 422 with a per-field message rather than a database-level
 * integrity error.
 *
 * =============================================================================
 * Ruling B2 — the appointment-to-membership relation, in full
 * =============================================================================
 * The manifest's ruling B2 was recorded as:
 *
 *     "1:N — one appointment → many memberships. Appointment is
 *      the instrument; membership is the person-level occupancy.
 *      On membership, governance_appointment_id is nullable
 *      (null = ex-officio, elected directly, or legacy)."
 *
 * The `governance_appointment_id` column is therefore:
 *
 *   · Nullable — a membership may exist without an appointment.
 *     The common case is an ex-officio membership (the CEO's Board
 *     seat is created by the by-law, not by an appointment
 *     instrument), but the column also accommodates legacy imports
 *     and any future business rule that permits direct recording.
 *
 *   · Non-unique — many memberships may reference the same
 *     appointment. A general-assembly resolution electing five
 *     directors creates five memberships, each pointing at the
 *     same appointment row.
 *
 *   · IMMUTABLE once set — the service layer refuses any
 *     `update()` that changes this column. A membership's
 *     provenance is a structural fact, not a mutable attribute.
 *
 * =============================================================================
 * Ruling B4 — the overlap invariant, and why it is NOT a schema rule
 * =============================================================================
 * The manifest's ruling B4 was recorded as:
 *
 *     "No two ACTIVE memberships for the same (person reference,
 *      position, body) may have overlapping date ranges. Enforce
 *      this in GovernanceMembershipService::register() and
 *      ::update(). Compare on [start_date, end_date] with end_date
 *      = NULL treated as +∞. Overlap → ValidationException."
 *
 * The overlap check is deliberately NOT expressed as a MySQL
 * constraint. Two reasons:
 *
 *   · The check compares against a COMPOSITE person key — either
 *     `user_id` when set, or the pair (`member_name_am`,
 *     `member_name_en`) when it is not. A MySQL UNIQUE index cannot
 *     span an OR-condition. A computed-key column would require a
 *     generated column and a functional index — a schema change
 *     with more coupling than the invariant warrants.
 *
 *   · The check compares against a TIME RANGE with a nullable upper
 *     bound. `end_date = NULL` is treated as +∞. A MySQL CHECK
 *     constraint cannot express "no two rows with overlapping
 *     [start, end] ranges" portably; a trigger would be required,
 *     and the platform does not use triggers.
 *
 * The service layer enforces the invariant transactionally: it
 * queries the current active memberships for the same tuple, tests
 * for overlap, and throws a `ValidationException` before the write.
 * The query is a single SELECT against the
 * `(governance_position_id, status)` composite index; it runs
 * inside the same transaction as the write, so two concurrent
 * writers cannot both pass the check.
 *
 * The migration deliberately does NOT include a schema-level
 * uniqueness or range constraint. A future reader who is tempted to
 * add one should read this section first — the invariant is
 * maintained in the service, and a schema change would fragment the
 * enforcement across two layers without closing the concurrency
 * window the service already closes transactionally.
 *
 * =============================================================================
 * Ruling B5 — the is_ex_officio flag, in full
 * =============================================================================
 * The manifest's ruling B5 recorded the four business decisions
 * #5, #6, #14, and #15 (are members elected, appointed, or mixed;
 * are committee members Board-appointed, ex-officio, or elected;
 * is the Board Secretary elected or appointed; does GM/CEO hold a
 * Board seat ex-officio) as CONFIGURABLE rather than code
 * constraints. Two columns on this table carry the configurability
 * for the membership-level part of those decisions:
 *
 *     is_ex_officio   —  boolean; whether the seat is held by
 *                        virtue of another office.
 *
 * `is_ex_officio` is deliberately NOT redundant with the
 * `appointment_mode` column on the `governance_appointments` table:
 *
 *   · `appointment_mode` describes the PROCESS by which the
 *     instrument that fills the seat came into being (elected,
 *     appointed, ex-officio, mixed).
 *
 *   · `is_ex_officio` on the membership describes the SEAT itself
 *     — whether the seat is by charter or by-law reserved for
 *     whoever holds a specific other office.
 *
 * The two may diverge. Consider a Board that is elected in a
 * single slate, with one seat reserved by the by-law for the CEO.
 * The appointment that fills the elected slate has
 * `appointment_mode = 'elected'`. The membership for the CEO seat
 * has `is_ex_officio = true` — the CEO occupies that seat by virtue
 * of being the CEO, not because the election put them there. The
 * election elected everyone else; the CEO's seat is a separate
 * fact.
 *
 * A future CR that decides to consolidate the two concepts would
 * add a migration that recomputes the flag from the appointment's
 * mode and then drops the column. This migration does not
 * anticipate that CR.
 *
 * =============================================================================
 * The status column — six values, and why it is not a boolean
 * =============================================================================
 * The migration declares `status` as a `VARCHAR(32)` with a default
 * of `'active'`. The six values (per the manifest's ruling B6) are:
 *
 *     active      — currently in force
 *     ended       — completed the natural end of its period
 *     suspended   — temporarily paused; may be resumed
 *     resigned    — the member voluntarily ended their occupancy
 *     removed     — the member was removed by an authority
 *     expired     — the membership's time bound elapsed without an
 *                    explicit end action
 *
 * A boolean `is_active` was considered and rejected for the same
 * reason the appointment migration rejected it: a boolean cannot
 * distinguish `ended` from `resigned` from `removed`, and those
 * distinctions have different legal, procedural, and reporting
 * consequences. A member who resigns is not the same as a member
 * who is removed; a term that ends naturally is not the same as a
 * term that expires due to a missed renewal.
 *
 * The `ended_reason` column is the free-text field for the specific
 * circumstances of the transition. It is populated by the service
 * when a membership reaches a terminal status (ended, resigned,
 * removed, expired) and is null for memberships that are still
 * active or suspended.
 *
 * =============================================================================
 * The start_date / end_date pair
 * =============================================================================
 * `start_date` is NOT NULL. A membership without a start date has
 * no temporal meaning — the domain's temporal model (ADR-GOV-006)
 * requires it.
 *
 * `end_date` is nullable. A null `end_date` means the membership
 * is open-ended: it has not ended, and its natural end (if any) is
 * not yet known. The service layer's overlap check treats null as
 * +∞.
 *
 * Both are `date`, not `datetime`. A membership begins and ends on
 * calendar days, matching the convention established by the three
 * sibling tables (`governance_bodies.effective_from`,
 * `governance_terms.effective_from`, `governance_appointments.effective_date`).
 *
 * The service layer (INV-4 in its docblock) validates
 * `start_date <= end_date` when both are set. The schema does not
 * enforce the ordering.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Six indexes support the query patterns this table must serve:
 *
 *   (tenant_id, status) — `gm_tenant_status_index`
 *       — The tenant-scoped list query: "list memberships in this
 *         tenant, optionally by status". Also serves the FK on
 *         `tenant_id` (leading column).
 *
 *   (governance_body_id, start_date) — `gm_body_start_index`
 *       — The primary list query: "list memberships of this body in
 *         chronological order". Also serves the FK on
 *         `governance_body_id` (leading column). The `start_date`
 *         trailing column supports the default `ORDER BY start_date
 *         DESC` without a filesort for the common case.
 *
 *   (governance_position_id, status) — `gm_position_status_index`
 *       — Serves three queries: "list memberships of this position",
 *         "count active memberships of this position", and the
 *         overlap-check query the service layer runs for INV-5 (which
 *         filters on position + status + date range). Also serves
 *         the FK on `governance_position_id`.
 *
 *   (governance_term_id) — `gm_term_index`
 *       — "Find the memberships scoped to this term". Also serves
 *         the FK on `governance_term_id`.
 *
 *   (governance_appointment_id) — `gm_appointment_index`
 *       — "Find the memberships created by this appointment". Also
 *         serves the FK on `governance_appointment_id`.
 *
 *   (user_id) — `gm_user_index`
 *       — "Find the memberships held by this user". Also serves
 *         the FK on `user_id`.
 *
 * No index on `is_ex_officio` — it is a low-cardinality boolean
 * whose filter is always applied on top of a body- or position-
 * scoped query, which the composite indexes above already serve.
 *
 * No index on `status` alone — status filters always come with a
 * scope (tenant, body, or position), and the composite indexes have
 * status as their trailing column, which is the correct order for
 * MySQL's index-scan behaviour.
 *
 * No index on `ended_reason` or the bilingual notes — those are
 * descriptive columns, not query surfaces.
 *
 * No index on `member_name_am` / `member_name_en` — a future CR
 * that adds a name-search feature would introduce a full-text or
 * prefix index at that time.
 *
 * =============================================================================
 * Foreign-key actions
 * =============================================================================
 * Six foreign keys:
 *
 *   tenant_id              → tenants.id              — restrictOnDelete
 *   governance_body_id     → governance_bodies.id    — cascadeOnDelete
 *   governance_position_id → governance_positions.id — restrictOnDelete
 *   governance_term_id     → governance_terms.id     — restrictOnDelete
 *   governance_appointment_id → governance_appointments.id — restrictOnDelete
 *   user_id                → users.id                — nullOnDelete
 *
 * All six carry `cascadeOnUpdate()`, matching the platform's FK
 * convention.
 *
 * The asymmetric choices reflect the ownership direction:
 *
 *   · The membership is OWNED by its body — a hard-delete of the
 *     body cascades. This is defensive: the domain does not
 *     hard-delete bodies in normal flow.
 *
 *   · The membership REFERENCES its position, term, and appointment
 *     — a hard-delete of any of the three is refused by the schema,
 *     because it would orphan a historical record. The service
 *     layer must resolve any such deletion explicitly.
 *
 *   · The membership REFERENCES its user — a hard-delete of the
 *     user nullifies the FK rather than cascading or refusing. This
 *     preserves the historical membership (the person's identity
 *     remains in the free-text name columns) even if the platform
 *     account is hard-deleted. In normal flow the user is
 *     soft-deleted, so this FK action rarely fires.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_140000_create_governance_memberships_table.php
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
 *     2026_09_18_140000_create_governance_memberships_table ... DONE
 *
 * Verify via phpMyAdmin: the `governance_memberships` table appears
 * with columns:
 *
 *     id, tenant_id, governance_body_id, governance_position_id,
 *     governance_term_id, governance_appointment_id, user_id,
 *     member_name_am, member_name_en, external_reference,
 *     start_date, end_date, status, is_ex_officio, ended_reason,
 *     notes_am, notes_en, deleted_at, created_at, updated_at
 *
 * Rollback (only during development) via Plesk PHP Command runner:
 *
 *     php artisan migrate:rollback --step=1
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Tables created:            1 (governance_memberships)
 * Columns added:             20
 * Foreign keys added:        6 (tenant_id, governance_body_id,
 *                               governance_position_id,
 *                               governance_term_id,
 *                               governance_appointment_id,
 *                               user_id)
 * Indexes added:             6 (all composite, plain)
 * Seeders added:             none (deferred to a future CR)
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `governance_memberships` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A governance membership — the temporal record of one person
 * occupying one position in one body, from a start date, for a
 * bounded or open-ended period, under an optional term and an
 * optional appointment instrument.
 *
 * The table does NOT store:
 *
 *   · Any documents. The appointment letter or the resignation
 *     notice lives in a document link (G11) when that phase ships.
 *   · Any attendance record. Attendance is a meeting-level fact
 *     (G5).
 *   · Any "current" boolean flag. Currentness is a function of the
 *     date range and the current date, computed at read time.
 *   · Any organizational seed data.
 *
 * =============================================================================
 * The person reference is a set of columns, not an FK
 * =============================================================================
 * The member is identified by one of:
 *
 *   · `user_id`              — the platform account, when one
 *                              exists.
 *   · `member_name_am`       — the Amharic name for a non-user
 *     `member_name_en`         member (at least one required when
 *                              no `user_id` is set).
 *   · `external_reference`   — an external identifier.
 *
 * See the file-level change-verification block §"Ruling B1" for the
 * reasoning. The "at least one identifier present" rule is enforced
 * by the service layer, not the schema.
 *
 * =============================================================================
 * The overlap invariant is maintained by the service, not the schema
 * =============================================================================
 * No two ACTIVE memberships for the same (person reference,
 * position, body) may have overlapping date ranges. This invariant
 * is enforced by `GovernanceMembershipService` at write time, inside
 * the same transaction as the write. See the file-level
 * change-verification block §"Ruling B4" for why no schema-level
 * constraint is present.
 *
 * =============================================================================
 * Foreign-key actions — asymmetric by design
 * =============================================================================
 *   · `governance_body_id` cascades on delete — the membership is
 *     owned by its body.
 *   · `governance_position_id`, `governance_term_id`, and
 *     `governance_appointment_id` restrict on delete — the
 *     membership references them; a delete must be resolved by the
 *     service layer, not silently orphaned.
 *   · `user_id` nullifies on delete — the historical membership is
 *     preserved even if the user account is hard-deleted; the
 *     free-text name columns carry the member's identity.
 *
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
        Schema::create('governance_memberships', function (Blueprint $table): void {
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
            // Owning governance body — immutable after registration.
            // -----------------------------------------------------------------
            $table->foreignId('governance_body_id')
                  ->constrained('governance_bodies')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Owning governance body (mandatory, immutable)');

            // -----------------------------------------------------------------
            // Position the membership fills — immutable after registration.
            // -----------------------------------------------------------------
            $table->foreignId('governance_position_id')
                  ->constrained('governance_positions')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Position the member occupies (mandatory, immutable)');

            // -----------------------------------------------------------------
            // Optional term — immutable when set.
            // -----------------------------------------------------------------
            $table->foreignId('governance_term_id')
                  ->nullable()
                  ->constrained('governance_terms')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Term the membership is scoped to; null for term-less memberships (immutable)');

            // -----------------------------------------------------------------
            // Optional appointment — immutable when set.
            // Per ruling B2, the appointment-to-membership relation is 1:N;
            // many memberships may reference the same appointment.
            // -----------------------------------------------------------------
            $table->foreignId('governance_appointment_id')
                  ->nullable()
                  ->constrained('governance_appointments')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Appointment that created this membership; null for ex-officio, direct, or legacy records (immutable)');

            // -----------------------------------------------------------------
            // Optional user FK — the platform account, when one exists.
            //
            // Nullifies on delete so a hard-deleted user account does not
            // destroy the historical membership.
            // -----------------------------------------------------------------
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Platform user account; null when the member is not a user');

            // -----------------------------------------------------------------
            // Bilingual free-text member names.
            //
            // Amharic first, English second — the platform's Amharic-first
            // policy reflected in column declaration order.
            //
            // Populated when the member is NOT a platform user. For a
            // membership whose `user_id` is set, these columns may still be
            // populated (to record the name as it appeared in the source
            // instrument) but are not required.
            // -----------------------------------------------------------------
            $table->string('member_name_am', 150)
                  ->nullable()
                  ->comment('Member name in Amharic; used when the member is not a platform user');

            $table->string('member_name_en', 150)
                  ->nullable()
                  ->comment('Member name in English; used when the member is not a platform user');

            // -----------------------------------------------------------------
            // External reference — an identifier from a source system.
            // -----------------------------------------------------------------
            $table->string('external_reference', 191)
                  ->nullable()
                  ->comment('External identifier for the member (e.g. employee number, shareholder record number)');

            // -----------------------------------------------------------------
            // Temporal bounds.
            //
            // `date`, not `datetime`. See the file-level change-verification
            // block §"The start_date / end_date pair".
            // -----------------------------------------------------------------
            $table->date('start_date')
                  ->comment('Calendar day the membership begins');

            $table->date('end_date')
                  ->nullable()
                  ->comment('Calendar day the membership ends; null for open-ended memberships');

            // -----------------------------------------------------------------
            // Lifecycle status — one of the six GovernanceMembershipStatus
            // values. VARCHAR + PHP enum, NOT MySQL ENUM.
            // -----------------------------------------------------------------
            $table->string('status', 32)
                  ->default('active')
                  ->comment('Lifecycle state; one of the GovernanceMembershipStatus enum values');

            // -----------------------------------------------------------------
            // Ex-officio flag — whether the seat is held by virtue of
            // another office. See the file-level change-verification block
            // §"Ruling B5".
            // -----------------------------------------------------------------
            $table->boolean('is_ex_officio')
                  ->default(false)
                  ->comment('Whether the seat is held by virtue of another office');

            // -----------------------------------------------------------------
            // End reason — populated when the membership reaches a terminal
            // status.
            // -----------------------------------------------------------------
            $table->string('ended_reason', 500)
                  ->nullable()
                  ->comment('Reason the membership ended; populated for terminal statuses');

            // -----------------------------------------------------------------
            // Bilingual notes. Amharic first, English second.
            // -----------------------------------------------------------------
            $table->string('notes_am', 500)
                  ->nullable()
                  ->comment('Optional short free-text notes in Amharic');

            $table->string('notes_en', 500)
                  ->nullable()
                  ->comment('Optional short free-text notes in English');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves historical references');

            $table->timestamps();

            // -----------------------------------------------------------------
            // Indexes.
            //
            // (tenant_id, status) serves the tenant-scoped list and the FK.
            //
            // (governance_body_id, start_date) serves the body-scoped
            // chronological list and the FK on governance_body_id.
            //
            // (governance_position_id, status) serves position-scoped lists,
            // position active-counts, the service's overlap-check query, and
            // the FK on governance_position_id.
            //
            // (governance_term_id) serves term lookups and the FK.
            // (governance_appointment_id) serves appointment-to-membership
            // lookups and the FK.
            // (user_id) serves user-to-membership lookups and the FK.
            // -----------------------------------------------------------------
            $table->index(['tenant_id', 'status'], 'gm_tenant_status_index');
            $table->index(['governance_body_id', 'start_date'], 'gm_body_start_index');
            $table->index(['governance_position_id', 'status'], 'gm_position_status_index');
            $table->index('governance_term_id', 'gm_term_index');
            $table->index('governance_appointment_id', 'gm_appointment_index');
            $table->index('user_id', 'gm_user_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_memberships');
    }
};