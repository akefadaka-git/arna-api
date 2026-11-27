<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_appointment_notification_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'cap_notice_tenant_fk')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->constrained('calendar_appointments', 'id', 'cap_notice_appointment_fk')->cascadeOnDelete();
            $table->foreignUuid('proposal_id')->constrained('calendar_appointment_reschedule_proposals', 'id', 'cap_notice_proposal_fk')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users', 'id', 'cap_notice_recipient_fk')->cascadeOnDelete();
            $table->string('action', 20);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('deliver_at')->useCurrent();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('idempotency_key', 190)->unique();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'deliver_at'], 'calendar_appt_notice_pending_idx');
            $table->index(['tenant_id', 'appointment_id'], 'calendar_appt_notice_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_appointment_notification_outbox');
    }
};
