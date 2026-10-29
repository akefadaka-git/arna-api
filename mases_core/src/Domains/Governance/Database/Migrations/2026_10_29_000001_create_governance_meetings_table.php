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
            $table->string('title_am', 200)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->string('meeting_type', 32)->default('ordinary');
            $table->string('status', 24)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('meeting_mode', 24)->default('in_person');
            $table->json('quorum_snapshot_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'governance_body_id', 'status'], 'governance_meetings_body_status_idx');
            $table->index(['tenant_id', 'scheduled_at'], 'governance_meetings_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meetings');
    }
};
