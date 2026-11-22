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
        Schema::create('document_evidence_capture_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('file_id');
            $table->unsignedInteger('file_version');
            $table->string('status', 24)->default('reserved');
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('reserved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->uuid('cleanup_claim_token')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('cleanup_claimed_at')->nullable();
            $table->timestamp('cleanup_lease_until')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'doc_capture_tenant_id_unique');
            $table->unique(['tenant_id', 'file_id', 'file_version'], 'doc_capture_source_unique');
            $table->index(['status', 'captured_at', 'cleanup_lease_until'], 'doc_capture_reconcile_idx');
            $table->foreign(['tenant_id', 'file_id'], 'doc_capture_file_tenant_fk')
                ->references(['tenant_id', 'id'])->on('files')->restrictOnDelete();
        });

        Schema::create('document_evidence_capture_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('capture_intent_id');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('event_type', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->foreign(['tenant_id', 'capture_intent_id'], 'doc_capture_event_intent_fk')
                ->references(['tenant_id', 'id'])->on('document_evidence_capture_intents')->restrictOnDelete();
            $table->index(['tenant_id', 'capture_intent_id', 'occurred_at'], 'doc_capture_event_timeline_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER document_evidence_capture_events_no_update BEFORE UPDATE ON document_evidence_capture_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document evidence capture events are append-only'");
            DB::unprepared("CREATE TRIGGER document_evidence_capture_events_no_delete BEFORE DELETE ON document_evidence_capture_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Document evidence capture events are append-only'");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER document_evidence_capture_events_no_update BEFORE UPDATE ON document_evidence_capture_events BEGIN SELECT RAISE(ABORT, 'Document evidence capture events are append-only'); END");
            DB::unprepared("CREATE TRIGGER document_evidence_capture_events_no_delete BEFORE DELETE ON document_evidence_capture_events BEGIN SELECT RAISE(ABORT, 'Document evidence capture events are append-only'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS document_evidence_capture_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS document_evidence_capture_events_no_delete');
        }

        Schema::dropIfExists('document_evidence_capture_events');
        Schema::dropIfExists('document_evidence_capture_intents');
    }
};
