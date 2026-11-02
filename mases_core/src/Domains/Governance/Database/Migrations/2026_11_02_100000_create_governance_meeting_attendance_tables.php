<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->restrictOnDelete();
            $table->string('meeting_code', 64);
            $table->string('title_am', 190)->nullable();
            $table->string('title_en', 190)->nullable();
            $table->string('meeting_kind', 24);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 24)->default('scheduled');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_code'], 'gm_tenant_code_uq');
            $table->index(['tenant_id', 'governance_body_id', 'starts_at'], 'gm_tenant_body_start_ix');
        });

        Schema::create('governance_meeting_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attendance_status', 24)->default('unrecorded');
            $table->dateTime('arrived_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'subject_type', 'subject_id'], 'gma_meeting_subject_uq');
            $table->index(['tenant_id', 'meeting_id', 'attendance_status'], 'gma_tenant_meeting_status_ix');
        });

        Schema::create('governance_meeting_proxies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->string('principal_type', 24);
            $table->unsignedBigInteger('principal_id');
            $table->foreignId('proxy_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at');
            $table->dateTime('deadline_at');
            $table->string('status', 24)->default('submitted');
            $table->text('instrument_reference')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'principal_type', 'principal_id'], 'gmp_meeting_principal_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmp_tenant_meeting_status_ix');
        });

        Schema::create('governance_meeting_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('attendance_id')->constrained('governance_meeting_attendance')->restrictOnDelete();
            $table->string('penalty_type', 24);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('ETB');
            $table->string('status', 24)->default('assessed');
            $table->text('reason')->nullable();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assessed_at');
            $table->timestamps();
            $table->unique(['attendance_id', 'penalty_type'], 'gmpen_attendance_type_uq');
            $table->index(['tenant_id', 'status', 'meeting_id'], 'gmpen_tenant_status_meeting_ix');
        });

        Schema::create('governance_penalty_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('penalty_id')->constrained('governance_meeting_penalties')->restrictOnDelete();
            $table->string('method', 24);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('ETB');
            $table->string('status', 24)->default('pending_finance');
            $table->string('reference', 100)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();
            $table->index(['tenant_id', 'penalty_id', 'status'], 'gps_tenant_penalty_status_ix');
        });

        Schema::create('governance_meeting_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 48);
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['tenant_id', 'meeting_id', 'occurred_at'], 'gma_activity_tenant_meeting_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_activity');
        Schema::dropIfExists('governance_penalty_settlements');
        Schema::dropIfExists('governance_meeting_penalties');
        Schema::dropIfExists('governance_meeting_proxies');
        Schema::dropIfExists('governance_meeting_attendance');
        Schema::dropIfExists('governance_meetings');
    }
};
