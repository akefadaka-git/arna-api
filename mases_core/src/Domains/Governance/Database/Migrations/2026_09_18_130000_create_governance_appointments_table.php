<?php

declare(strict_types=1);

// Tenant-bound appointment records with indexed effective dates.



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