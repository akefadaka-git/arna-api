<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_ai_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('agent_key', 100);
            $table->string('task_type', 64);
            $table->string('task_template_version', 64);
            $table->string('model_provider', 64)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->string('model_version', 120)->nullable();
            $table->string('status', 16)->default('proposed');
            $table->string('title', 180);
            $table->text('summary');
            $table->json('proposal');
            $table->json('evidence_refs');
            $table->char('evidence_sha256', 64);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('limitations')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'legal_entity_id'], 'finance_ai_proposals_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'status', 'created_at'], 'finance_ai_proposals_scope_status_index');
            $table->index(['tenant_id', 'agent_key', 'created_at'], 'finance_ai_proposals_agent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_ai_proposals');
    }
};
