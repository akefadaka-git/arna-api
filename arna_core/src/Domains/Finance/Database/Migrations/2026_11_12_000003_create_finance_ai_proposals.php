<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER finance_ai_proposals_immutable_update BEFORE UPDATE ON finance_ai_proposals FOR EACH ROW BEGIN IF OLD.tenant_id <> NEW.tenant_id OR OLD.legal_entity_id <> NEW.legal_entity_id OR OLD.requested_by <> NEW.requested_by OR OLD.agent_key <> NEW.agent_key OR OLD.task_type <> NEW.task_type OR OLD.task_template_version <> NEW.task_template_version OR NOT (OLD.model_provider <=> NEW.model_provider) OR NOT (OLD.model_name <=> NEW.model_name) OR NOT (OLD.model_version <=> NEW.model_version) OR OLD.title <> NEW.title OR OLD.summary <> NEW.summary OR NOT (OLD.proposal <=> NEW.proposal) OR NOT (OLD.evidence_refs <=> NEW.evidence_refs) OR OLD.evidence_sha256 <> NEW.evidence_sha256 OR NOT (OLD.confidence <=> NEW.confidence) OR NOT (OLD.limitations <=> NEW.limitations) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance AI proposal evidence and content are immutable'; END IF; IF OLD.status <> 'proposed' OR NEW.status NOT IN ('approved', 'rejected') OR NEW.reviewed_by IS NULL OR NEW.reviewed_at IS NULL OR NEW.review_reason IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance AI proposal review transition is invalid'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_ai_proposals_immutable_delete BEFORE DELETE ON finance_ai_proposals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance AI proposals are retained as audit evidence'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS finance_ai_proposals_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS finance_ai_proposals_immutable_delete');
        }

        Schema::dropIfExists('finance_ai_proposals');
    }
};
