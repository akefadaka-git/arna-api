<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meeting_electorate_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('source_register_version', 120);
            $table->timestamp('cutoff_at');
            $table->unsignedInteger('entry_count')->default(0);
            $table->unsignedBigInteger('source_ledger_max_id')->default(0);
            $table->char('source_hash', 64);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 200)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_id', 'version'], 'gmes_tenant_meeting_version_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmes_meeting_status_ix');
        });

        Schema::create('governance_meeting_electorate_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('snapshot_id')->constrained('governance_meeting_electorate_snapshots')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->json('shareholdings_json');
            $table->decimal('voting_units', 24, 4)->nullable();
            $table->string('eligibility_status', 24);
            $table->string('eligibility_reason', 500)->nullable();
            $table->char('source_record_hash', 64);
            $table->timestamps();
            $table->unique(['snapshot_id', 'shareholder_id'], 'gmee_snapshot_shareholder_uq');
            $table->index(['tenant_id', 'snapshot_id', 'eligibility_status'], 'gmee_snapshot_status_ix');
        });

        Schema::create('governance_quorum_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('meeting_kind', 32);
            $table->string('rule_code', 80);
            $table->json('rule_payload');
            $table->char('rule_definition_hash', 64);
            $table->string('legal_basis', 1000);
            $table->string('approval_reference', 200)->nullable();
            $table->char('approval_document_hash', 64)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('legal_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('legal_verified_at')->nullable();
            $table->string('legal_verification_reference', 200)->nullable();
            $table->char('legal_verification_document_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_kind', 'status'], 'gqr_tenant_kind_status_ix');
        });

        Schema::create('governance_meeting_quorum_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('governance_meeting_electorate_snapshots')->restrictOnDelete();
            $table->foreignId('quorum_rule_id')->nullable()->constrained('governance_quorum_rules')->restrictOnDelete();
            $table->string('status', 12);
            $table->string('reason_code', 80)->nullable();
            $table->json('result_payload');
            $table->char('input_hash', 64);
            $table->foreignId('evaluated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_id', 'evaluated_at'], 'gmqe_meeting_evaluated_ix');
        });

        Schema::create('governance_meeting_proxy_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('proxy_id')->constrained('governance_meeting_proxies')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('previous_status', 20)->nullable();
            $table->string('next_status', 20)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('evidence_reference', 500)->nullable();
            $table->char('previous_event_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_id', 'proxy_id'], 'gmpe_proxy_history_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_proxy_events');
        Schema::dropIfExists('governance_meeting_quorum_evaluations');
        Schema::dropIfExists('governance_quorum_rules');
        Schema::dropIfExists('governance_meeting_electorate_entries');
        Schema::dropIfExists('governance_meeting_electorate_snapshots');
    }
};
