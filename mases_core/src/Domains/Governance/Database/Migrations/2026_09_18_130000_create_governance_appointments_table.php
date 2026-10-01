<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 1 of 31
 * =============================================================================
 *
 * File:            Database/Migrations/2026_09_18_130000_create_governance_appointments_table.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (migration)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `governance_appointments` table was
 *   present in the schema. The `migrations` table's highest applied
 *   Governance migration was
 *   `2026_09_18_120000_create_governance_terms_table` (G2b file 13
 *   of 27). The Governance domain had three persistent entities —
 *   `governance_bodies` (G2a), `governance_positions` (G2b), and
 *   `governance_terms` (G2b) — and no way to record the INSTRUMENT
 *   by which a person came to occupy a position: a resolution, a
 *   board decision, a general-assembly act, an external document, or
 *   a by-law provision.
 *
 *   Consequence: a future membership record (G2c, later files) would
 *   have no way to express "this user was appointed Chairperson of
 *   the Board by resolution 2026-042 on Hamle 1, 2018 E.C." as
 *   distinct from "this user is the Chairperson of the Board". The
 *   appointment concept — the record of WHO decided, HOW, and WHEN —
 *   had no structural home.
 *
 * After:
 *   One migration that creates the `governance_appointments` table.
 *
 *   What the table stores:
 *
 *     · The identity of an appointment within a specific body — the
 *       instrument that creates one or more governance memberships.
 *
 *     · The instrument's source: its type (one of the five declared
 *       values), its external reference (a resolution number, a
 *       document code), and its date.
 *
 *     · The instrument's effective date — the moment from which the
 *       appointments it creates take effect. This is distinct from
 *       the instrument's own date; an appointment may become
 *       effective before or after the instrument is recorded.
 *
 *     · The appointment mode — one of `elected`, `appointed`,
 *       `ex_officio`, `mixed` — recording HOW the appointment was
 *       made. This is the configurable field that reflects business
 *       decisions #5 / #6 / #14 / #15 of the G0 assessment without
 *       encoding any of them as a code constraint.
 *
 *     · The instrument's lifecycle status — one of `draft`, `active`,
 *       `revoked`, `superseded`.
 *
 *     · A bilingual title and bilingual notes for the instrument.
 *
 *   What the table does NOT store:
 *
 *     · Any individual person's identity. The membership record
 *       (G2c, later files) is what binds a person to a position
 *       through an appointment. This table is the INSTRUMENT, not
 *       the occupancy.
 *
 *     · Any specific resolution, decision, or external document.
 *       The `instrument_reference` column is a free-text identifier
 *       — a resolution number, a document code — NOT a foreign key
 *       to a resolutions table that does not yet exist. When G8
 *       delivers `governance_resolutions`, a future CR may add an
 *       optional `resolution_id` FK to this table. That CR is
 *       outside this delivery's scope.
 *
 *     · Any organizational seed data. Appointments are created in
 *       response to specific governance acts; no seeder is delivered
 *       by G2c.
 *
 *     · Any reference to the MEMBERSHIP rows the appointment
 *       creates. The direction is memberships → appointment, not the
 *       reverse.
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
 * §6). An appointment belongs to a body, which belongs to a tenant —
 * but the appointment carries its own `tenant_id` for query
 * efficiency and for defence in depth.
 *
 * On delete: restrict.
 *
 * -----------------------------------------------------------------------------
 * governance_body_id
 * -----------------------------------------------------------------------------
 * The body whose positions this appointment populates. NOT NULL.
 * IMMUTABLE after registration — an appointment is defined by its
 * body and cannot be moved. The service layer enforces this.
 *
 * On delete: cascade. An appointment is owned by its body; if the
 * body is hard-deleted (which the domain does not do in normal flow),
 * the appointment goes with it.
 *
 * -----------------------------------------------------------------------------
 * governance_position_id
 * -----------------------------------------------------------------------------
 * The specific position this appointment populates, when the
 * appointment targets a single position. NULLABLE because an
 * appointment instrument may create multiple memberships across
 * multiple positions (e.g., a general-assembly resolution electing
 * the entire Board, each member filling a different position).
 *
 * IMMUTABLE after registration.
 *
 * On delete: restrict. Deleting a position that has appointments is
 * an integrity violation the service layer must resolve; the schema
 * refuses.
 *
 * -----------------------------------------------------------------------------
 * governance_term_id
 * -----------------------------------------------------------------------------
 * The term under which the appointment is made, when the appointment
 * is scoped to a specific term. NULLABLE because some appointments
 * — particularly ex-officio seats and transitional appointments —
 * are not tied to a specific term.
 *
 * IMMUTABLE after registration.
 *
 * On delete: restrict. Same reasoning as `governance_position_id`.
 *
 * -----------------------------------------------------------------------------
 * instrument_type
 * -----------------------------------------------------------------------------
 * VARCHAR(32), NOT NULL. The kind of instrument that creates the
 * appointment. One of the five values declared by
 * `GovernanceAppointmentInstrumentType` (G2c file 4 of 31):
 *
 *     resolution          — a governance resolution (G8)
 *     board_decision      — a decision recorded by the Board
 *     general_assembly    — an act of the General Assembly
 *     external_document   — a document outside the platform
 *     by_law              — a provision of the by-law or charter
 *
 * VARCHAR + PHP enum, NOT MySQL ENUM — the platform's convention for
 * a controlled vocabulary that may evolve via CR.
 *
 * -----------------------------------------------------------------------------
 * instrument_reference
 * -----------------------------------------------------------------------------
 * VARCHAR(191), NULLABLE. A free-text identifier for the instrument
 * — a resolution number ("2026-042"), a document code, an external
 * reference number.
 *
 * Bounded at 191 characters to match the platform's standard for
 * indexed-string columns and to leave room for a future index if a
 * CR adds a lookup-by-reference feature. Not indexed in G2c: the
 * column is descriptive metadata, not a query surface.
 *
 * Deliberately NOT a foreign key. See the file-level change-
 * verification block §"What the table does NOT store".
 *
 * -----------------------------------------------------------------------------
 * instrument_date
 * -----------------------------------------------------------------------------
 * DATE, NULLABLE. The calendar day on which the instrument was
 * issued — the day the resolution passed, the day the Board decided,
 * the day the external document bears.
 *
 * NULLABLE because some historical appointments are recorded without
 * a known instrument date — the instrument exists but its date has
 * been lost or was never recorded.
 *
 * `date`, not `datetime` — an instrument's issue date is a calendar
 * day, not an instant. This matches the convention established by
 * `governance_bodies.effective_from` and `governance_terms.effective_from`.
 *
 * -----------------------------------------------------------------------------
 * effective_date
 * -----------------------------------------------------------------------------
 * DATE, NOT NULL. The calendar day from which the appointments
 * created by this instrument take effect. May differ from
 * `instrument_date` in either direction:
 *
 *   · An appointment decided today but effective from next Hamle 1.
 *   · A retroactive appointment effective before the instrument was
 *     issued (a corrective resolution).
 *
 * NOT NULL because an appointment that has no effective date has no
 * temporal meaning — it is the anchor the domain's temporal model
 * (ADR-GOV-006) rests on.
 *
 * The service layer (file 7 of 31) enforces the cross-field
 * invariant `effective_date >= instrument_date` when `instrument_date`
 * is non-null — a future-dated instrument is possible but a
 * retroactively effective instrument is not the platform's default
 * posture.
 *
 * -----------------------------------------------------------------------------
 * appointment_mode
 * -----------------------------------------------------------------------------
 * VARCHAR(32), NOT NULL. HOW the appointment was made. One of the
 * four values declared by `GovernanceAppointmentMode` (file 3 of 31):
 *
 *     elected    — chosen by a vote
 *     appointed  — designated by an appointing authority
 *     ex_officio — held by virtue of another role
 *     mixed      — a hybrid (e.g., a resolution electing a slate
 *                  that was itself nominated by a committee)
 *
 * This is the field through which the G0 business decisions #5
 * (elected/appointed/mixed for members), #6 (committee member
 * acquisition), #14 (Board Secretary), and #15 (GM/CEO ex-officio)
 * are made CONFIGURABLE — the schema does not constrain the answer;
 * the enum records whichever answer the organization supplies.
 *
 * -----------------------------------------------------------------------------
 * status
 * -----------------------------------------------------------------------------
 * VARCHAR(32), NOT NULL, default `draft`. The instrument's lifecycle
 * state. One of the four values declared by
 * `GovernanceAppointmentStatus` (file 2 of 31):
 *
 *     draft       — recorded but not yet in force
 *     active      — currently in force
 *     revoked     — withdrawn before its natural end
 *     superseded  — replaced by a later instrument
 *
 * Default `draft` because the natural creation flow records an
 * appointment before it is confirmed. A separate service method
 * transitions it to `active`.
 *
 * -----------------------------------------------------------------------------
 * title_am / title_en
 * -----------------------------------------------------------------------------
 * Bilingual short titles, VARCHAR(150), NULLABLE. Amharic first,
 * English second — the platform's Amharic-first policy reflected in
 * column declaration order.
 *
 * The title is a human-entered string — "የቦርድ አባላት ሹመት 2018" /
 * "Board Appointment 2026" — not a computed value. Both nullable
 * because an appointment instrument may be recorded with only its
 * reference and dates; the title is a convenience for list views.
 *
 * The service layer does NOT require a title at write time. A future
 * CR that decides titles are mandatory would add a service-layer
 * check; the schema remains permissive.
 *
 * -----------------------------------------------------------------------------
 * notes_am / notes_en
 * -----------------------------------------------------------------------------
 * Bilingual free-text notes, VARCHAR(500), NULLABLE. Bounded at 500
 * characters because the columns are intended for a short
 * explanatory note — the circumstances, the rationale, the quorum
 * at the deciding meeting — not for the instrument's full text.
 * The full text of a resolution lives on the resolution record when
 * G8 ships; the full text of an external document lives wherever
 * that document is stored.
 *
 * -----------------------------------------------------------------------------
 * is_active — NOT a column
 * -----------------------------------------------------------------------------
 * Unlike `governance_bodies`, `governance_positions`, and
 * `governance_terms`, this table has NO separate `is_active`
 * boolean. The `status` column already carries the lifecycle
 * (draft/active/revoked/superseded), and having both would create
 * two sources of truth for the same fact. Where the sibling tables
 * use `is_active` for a binary state, this table uses `status` for
 * the four-state lifecycle.
 *
 * A reader comparing this migration to its siblings should not treat
 * the difference as an inconsistency — it is a deliberate design
 * choice driven by the entity's richer lifecycle.
 *
 * -----------------------------------------------------------------------------
 * deleted_at
 * -----------------------------------------------------------------------------
 * Soft-delete timestamp, nullable. An appointment is never hard-
 * deleted; memberships reference it, and an appointment that is no
 * longer in force is `revoked` or `superseded`, not deleted.
 * Soft-deletion is reserved for an appointment recorded in error.
 *
 * -----------------------------------------------------------------------------
 * created_at / updated_at
 * -----------------------------------------------------------------------------
 * Standard Eloquent timestamps.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Four indexes support the query patterns this table must serve:
 *
 *   (tenant_id, status) — `ga_tenant_status_index`
 *       — The tenant-scoped list query: "list appointments in this
 *         tenant, optionally by status". Also serves the FK on
 *         `tenant_id` (leading column).
 *
 *   (governance_body_id, status) — `ga_body_status_index`
 *       — The primary list query: "list appointments of this body,
 *         optionally by status". Also serves the FK on
 *         `governance_body_id` (leading column).
 *
 *   (governance_position_id) — `ga_position_index`
 *       — "Find the appointments that populate this position". Also
 *         serves the FK on `governance_position_id`.
 *
 *   (governance_term_id) — `ga_term_index`
 *       — "Find the appointments scoped to this term". Also serves
 *         the FK on `governance_term_id`.
 *
 * No index on `instrument_type`, `appointment_mode`, `instrument_date`,
 * or `effective_date`. Each is a low-cardinality or descriptive column
 * whose query pattern, when one emerges, will use the body-scoped or
 * tenant-scoped composite index as its entry point.
 *
 * No index on `status` alone. The status filter is always applied on
 * top of a tenant or body scope; the composite indexes above have
 * status as their trailing column, which is the correct order for
 * MySQL's index-scan behaviour.
 *
 * No index on `instrument_reference`. A future CR that adds a
 * "find by reference" lookup would introduce the index at that time.
 *
 * =============================================================================
 * Foreign-key actions
 * =============================================================================
 * Four foreign keys:
 *
 *   tenant_id              → tenants.id              — restrictOnDelete
 *   governance_body_id     → governance_bodies.id    — cascadeOnDelete
 *   governance_position_id → governance_positions.id — restrictOnDelete
 *   governance_term_id     → governance_terms.id     — restrictOnDelete
 *
 * All four carry `cascadeOnUpdate()`, matching the platform's FK
 * convention.
 *
 * The asymmetric cascade / restrict choice between body and position
 * / term reflects the ownership direction: an appointment is
 * OWNED by its body (cascade), but REFERENCES its position and term
 * (restrict — the service layer must resolve any deletion of a
 * position or term that has appointments, because such a deletion
 * would leave orphaned historical records).
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_130000_create_governance_appointments_table.php
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
 *     2026_09_18_130000_create_governance_appointments_table ... DONE
 *
 * Verify via phpMyAdmin: the `governance_appointments` table appears
 * with columns:
 *
 *     id, tenant_id, governance_body_id, governance_position_id,
 *     governance_term_id, instrument_type, instrument_reference,
 *     instrument_date, effective_date, appointment_mode, status,
 *     title_am, title_en, notes_am, notes_en, deleted_at,
 *     created_at, updated_at
 *
 * Rollback (only during development) via Plesk PHP Command runner:
 *
 *     php artisan migrate:rollback --step=1
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Tables created:            1 (governance_appointments)
 * Columns added:             18
 * Foreign keys added:        4 (tenant_id, governance_body_id,
 *                               governance_position_id,
 *                               governance_term_id)
 * Indexes added:             4 (all composite, plain)
 * Seeders added:             none (deferred to a future CR)
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `governance_appointments` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A governance appointment — the INSTRUMENT (a resolution, a board
 * decision, a general-assembly act, an external document, or a by-law
 * provision) that creates one or more governance memberships.
 *
 * The table does NOT store:
 *
 *   · Any individual person's identity. The membership record
 *     (later G2c files) is what binds a person to a position through
 *     an appointment.
 *   · Any specific resolution, decision, or external document. The
 *     `instrument_reference` column is a free-text identifier; a
 *     future CR may add an optional `resolution_id` FK when G8
 *     delivers `governance_resolutions`.
 *   · Any organizational seed data.
 *
 * =============================================================================
 * Appointment scope — per body, not per position
 * =============================================================================
 * An appointment belongs to a body, not to a position. A single
 * appointment (e.g., a general-assembly resolution) may create many
 * memberships across many positions. The `governance_position_id`
 * and `governance_term_id` columns are NULLABLE — they carry the
 * scope when the instrument targets exactly one position or term,
 * and are null when the instrument is broader.
 *
 * =============================================================================
 * is_active is deliberately NOT a column
 * =============================================================================
 * The `status` column already carries the lifecycle. Adding an
 * `is_active` boolean alongside would create two sources of truth.
 * See the file-level change-verification block §"is_active — NOT a
 * column" for the full reasoning.
 *
 * =============================================================================
 * Business decisions are configurable, not encoded
 * =============================================================================
 * The `appointment_mode` column records HOW the appointment was made
 * — `elected`, `appointed`, `ex_officio`, or `mixed`. The schema
 * does not constrain which mode applies to which position on which
 * body. The G0 business decisions #5, #6, #14, and #15 are answered
 * by the DATA an operator supplies, not by a schema constraint.
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
        Schema::create('governance_appointments', function (Blueprint $table): void {
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
            // Optional target position — immutable when set.
            // -----------------------------------------------------------------
            $table->foreignId('governance_position_id')
                  ->nullable()
                  ->constrained('governance_positions')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Target position; null when the instrument spans multiple positions (immutable)');

            // -----------------------------------------------------------------
            // Optional target term — immutable when set.
            // -----------------------------------------------------------------
            $table->foreignId('governance_term_id')
                  ->nullable()
                  ->constrained('governance_terms')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Target term; null when the instrument is not scoped to a term (immutable)');

            // -----------------------------------------------------------------
            // Instrument type — one of GovernanceAppointmentInstrumentType.
            // -----------------------------------------------------------------
            $table->string('instrument_type', 32)
                  ->comment('Instrument type; one of the GovernanceAppointmentInstrumentType enum values');

            // -----------------------------------------------------------------
            // Instrument reference — free-text identifier.
            // -----------------------------------------------------------------
            $table->string('instrument_reference', 191)
                  ->nullable()
                  ->comment('Free-text identifier of the instrument (e.g. resolution number, document code)');

            // -----------------------------------------------------------------
            // Instrument issue date — calendar day, nullable.
            // -----------------------------------------------------------------
            $table->date('instrument_date')
                  ->nullable()
                  ->comment('Calendar day the instrument was issued; null when unknown');

            // -----------------------------------------------------------------
            // Effective date — calendar day from which appointments take effect.
            // -----------------------------------------------------------------
            $table->date('effective_date')
                  ->comment('Calendar day from which the appointments created by this instrument take effect');

            // -----------------------------------------------------------------
            // Appointment mode — one of GovernanceAppointmentMode.
            // -----------------------------------------------------------------
            $table->string('appointment_mode', 32)
                  ->comment('Appointment mode; one of the GovernanceAppointmentMode enum values');

            // -----------------------------------------------------------------
            // Lifecycle status — one of GovernanceAppointmentStatus.
            // -----------------------------------------------------------------
            $table->string('status', 32)
                  ->default('draft')
                  ->comment('Lifecycle state; one of the GovernanceAppointmentStatus enum values');

            // -----------------------------------------------------------------
            // Bilingual titles. Amharic first, English second.
            // -----------------------------------------------------------------
            $table->string('title_am', 150)
                  ->nullable()
                  ->comment('Short title in Amharic');

            $table->string('title_en', 150)
                  ->nullable()
                  ->comment('Short title in English');

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
            // (tenant_id, status) serves the tenant-scoped list query
            // and the FK on tenant_id.
            //
            // (governance_body_id, status) serves the body-scoped
            // list query and the FK on governance_body_id.
            //
            // (governance_position_id) serves position lookups and
            // the FK on governance_position_id.
            //
            // (governance_term_id) serves term lookups and the FK on
            // governance_term_id.
            // -----------------------------------------------------------------
            $table->index(['tenant_id', 'status'], 'ga_tenant_status_index');
            $table->index(['governance_body_id', 'status'], 'ga_body_status_index');
            $table->index('governance_position_id', 'ga_position_index');
            $table->index('governance_term_id', 'ga_term_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_appointments');
    }
};