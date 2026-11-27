<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_attendance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('attendance_id')->constrained('governance_meeting_attendance')->restrictOnDelete();
            $table->string('proposed_status', 24);
            $table->dateTime('proposed_arrived_at')->nullable();
            $table->text('reason');
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gac_tenant_meeting_status_ix');
        });

        Schema::create('governance_penalty_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('penalty_id')->constrained('governance_meeting_penalties')->restrictOnDelete();
            $table->text('reason');
            $table->string('evidence_reference', 2000)->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['penalty_id'], 'gpd_penalty_once_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gpd_tenant_meeting_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_penalty_disputes');
        Schema::dropIfExists('governance_attendance_corrections');
    }
};
