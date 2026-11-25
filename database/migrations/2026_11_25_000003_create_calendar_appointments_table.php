<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->unique()->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->restrictOnDelete();
            $table->string('appointment_scope', 20)->default('personal');
            $table->string('status', 20)->default('requested');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by_user_id', 'status'], 'calendar_appt_requester_idx');
            $table->index(['tenant_id', 'host_user_id', 'status'], 'calendar_appt_host_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_appointments');
    }
};
