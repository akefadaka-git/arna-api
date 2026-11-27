<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_appointment_reschedule_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'cap_resched_tenant_fk')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->constrained('calendar_appointments', 'id', 'cap_resched_appointment_fk')->cascadeOnDelete();
            $table->foreignId('proposer_user_id')->constrained('users', 'id', 'cap_resched_proposer_fk')->restrictOnDelete();
            $table->foreignId('counterparty_user_id')->constrained('users', 'id', 'cap_resched_counterparty_fk')->restrictOnDelete();
            $table->timestamp('proposed_starts_at');
            $table->timestamp('proposed_ends_at');
            $table->string('timezone', 64);
            $table->string('reason', 1000)->nullable();
            $table->unsignedInteger('base_appointment_version');
            $table->unsignedInteger('base_event_version');
            $table->string('status', 20)->default('pending');
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users', 'id', 'cap_resched_responder_fk')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'appointment_id', 'status'], 'calendar_appt_resched_state_idx');
            $table->index(['tenant_id', 'proposer_user_id', 'created_at'], 'calendar_appt_resched_proposer_idx');
            $table->index(['tenant_id', 'counterparty_user_id', 'status'], 'calendar_appt_resched_party_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_appointment_reschedule_proposals');
    }
};
