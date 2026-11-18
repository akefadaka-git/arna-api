<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_workflow_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('organization_unit_id');
            $table->unsignedBigInteger('governance_body_id')->nullable();
            $table->unsignedBigInteger('governance_position_id')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->char('document_type_number', 2);
            $table->string('title', 255);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedBigInteger('submitted_file_id')->nullable();
            $table->unsignedInteger('submitted_file_version')->nullable();
            $table->char('submitted_file_sha256', 64)->nullable();
            $table->unsignedBigInteger('issued_document_id')->nullable();
            $table->timestamp('last_transition_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'doc_workflow_tenant_id_unique');
            $table->unique(['tenant_id', 'issued_document_id'], 'doc_workflow_issued_document_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'doc_wf_entity_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'organization_unit_id'], 'doc_wf_issuer_unit_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->foreign('document_type_number', 'doc_wf_type_fk')
                ->references('numeric_code')->on('document_type_catalogue')->restrictOnDelete();
            $table->foreign(['tenant_id', 'submitted_file_id'], 'doc_wf_submitted_file_tenant_fk')
                ->references(['tenant_id', 'id'])->on('files')->restrictOnDelete();
            $table->foreign(['tenant_id', 'issued_document_id'], 'doc_wf_issued_fk')
                ->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at'], 'doc_workflow_tenant_status_idx');
        });

        Schema::create('document_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 24);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['tenant_id', 'workflow_id', 'revision'], 'doc_workflow_event_revision_unique');
            $table->foreign(['tenant_id', 'workflow_id'], 'doc_wf_event_workflow_fk')
                ->references(['tenant_id', 'id'])->on('document_workflow_aggregates')->restrictOnDelete();
            $table->index(['tenant_id', 'workflow_id', 'occurred_at'], 'doc_workflow_event_timeline_idx');
        });

        // Enforce append-only history below the ORM layer as well.
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER document_workflow_events_no_update BEFORE UPDATE ON document_workflow_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document workflow events are append-only'");
            DB::unprepared("CREATE TRIGGER document_workflow_events_no_delete BEFORE DELETE ON document_workflow_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document workflow events are append-only'");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER document_workflow_events_no_update BEFORE UPDATE ON document_workflow_events BEGIN SELECT RAISE(ABORT, 'Document workflow events are append-only'); END");
            DB::unprepared("CREATE TRIGGER document_workflow_events_no_delete BEFORE DELETE ON document_workflow_events BEGIN SELECT RAISE(ABORT, 'Document workflow events are append-only'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS document_workflow_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS document_workflow_events_no_delete');
        }

        Schema::dropIfExists('document_workflow_events');
        Schema::dropIfExists('document_workflow_aggregates');
    }
};
