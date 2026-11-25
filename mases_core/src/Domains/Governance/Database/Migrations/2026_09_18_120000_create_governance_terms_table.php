<?php

declare(strict_types=1);

// Tenant-bound governance terms with effective-date lookup indexes.



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
 * Overlapping active terms are rejected by the service
 * =============================================================================
 * The schema does not encode date-range exclusion; the service enforces
 * non-overlap among active terms using a body-row lock. See the service
 * contract for the concurrency guarantee.
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