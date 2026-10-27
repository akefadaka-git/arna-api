<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1 — represent appointment effective intervals and acting relationships.
 *
 * Calendar dates are inclusive. A null effective_until is open-ended.
 * Acting appointments point to the substantive primary appointment; their
 * permitted actions remain governed by the existing action-bound authority
 * grant system and are not implicitly inherited from the primary incumbent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_appointments', function (Blueprint $table): void {
            $table->date('effective_until')
                ->nullable()
                ->after('effective_date')
                ->comment('Inclusive final calendar day of appointment; null means open-ended');

            $table->string('appointment_role', 16)
                ->default('primary')
                ->after('appointment_mode')
                ->comment('primary or acting; distinct from how the appointment was obtained');

            $table->foreignId('acting_for_appointment_id')
                ->nullable()
                ->after('appointment_role')
                ->constrained('governance_appointments')
                ->restrictOnDelete()
                ->comment('Substantive primary appointment for an acting assignment; null for primary');

            $table->index(
                ['tenant_id', 'governance_position_id', 'appointment_role', 'status'],
                'ga_position_role_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('governance_appointments', function (Blueprint $table): void {
            $table->dropIndex('ga_position_role_status_index');
            $table->dropConstrainedForeignId('acting_for_appointment_id');
            $table->dropColumn(['appointment_role', 'effective_until']);
        });
    }
};
