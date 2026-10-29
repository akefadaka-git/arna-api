<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->string('title_am', 200)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'position'], 'gov_agenda_meeting_position_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gov_agenda_tenant_meeting_status_idx');
        });

        Schema::create('governance_meeting_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('governance_membership_id')->constrained('governance_memberships')->restrictOnDelete();
            $table->string('attendance_status', 24)->default('present');
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'governance_membership_id'], 'gov_attendance_meeting_member_uq');
            $table->index(['tenant_id', 'meeting_id', 'attendance_status'], 'gov_attendance_tenant_status_idx');
        });

        Schema::create('governance_meeting_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'meeting_id', 'occurred_at'], 'gov_meeting_audit_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_audit_events');
        Schema::dropIfExists('governance_meeting_attendance');
        Schema::dropIfExists('governance_meeting_agenda_items');
    }
};
