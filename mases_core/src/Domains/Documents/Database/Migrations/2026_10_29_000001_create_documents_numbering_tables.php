<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_counters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->restrictOnDelete();
            $table->foreignId('governance_position_id')->constrained('governance_positions')->restrictOnDelete();
            $table->string('organization_code', 12);
            $table->string('document_type_code', 8);
            $table->string('department_code', 12);
            $table->string('issuing_role_code', 12);
            $table->unsignedSmallInteger('ec_year');
            $table->unsignedInteger('next_sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'legal_entity_id', 'organization_unit_id', 'governance_position_id', 'document_type_code', 'ec_year'],
                'doc_counter_scope_unique'
            );
            $table->index(['tenant_id', 'is_active'], 'doc_counter_tenant_active_idx');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->restrictOnDelete();
            $table->foreignId('governance_position_id')->constrained('governance_positions')->restrictOnDelete();
            $table->string('organization_code', 12);
            $table->string('document_type_code', 8);
            $table->string('department_code', 12);
            $table->string('issuing_role_code', 12);
            $table->unsignedSmallInteger('ec_year');
            $table->unsignedInteger('sequence_number');
            $table->string('canonical_number', 100)->unique();
            $table->string('title', 255);
            $table->unsignedBigInteger('file_id');
            $table->unsignedInteger('file_version');
            $table->string('status', 16)->default('issued');
            $table->char('request_hash', 64);
            $table->string('idempotency_key', 128);
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('issued_by');
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 1000)->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 1000)->nullable();
            $table->timestamps();

            $table->foreign('file_id', 'documents_file_fk')
                ->references('id')->on('files')->restrictOnDelete();
            $table->unique(['tenant_id', 'idempotency_key'], 'documents_idempotency_unique');
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'organization_unit_id', 'governance_position_id', 'document_type_code', 'ec_year', 'sequence_number'],
                'documents_number_scope_unique'
            );
            $table->index(['tenant_id', 'status', 'issued_at'], 'documents_tenant_status_issued_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'issued_at'], 'documents_entity_issued_idx');
        });

        Schema::create('document_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 16);
            $table->unsignedBigInteger('actor_user_id');
            $table->string('reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('document_id', 'document_lifecycle_document_fk')
                ->references('id')->on('documents')->restrictOnDelete();
            $table->index(['tenant_id', 'document_id', 'occurred_at'], 'doc_lifecycle_tenant_doc_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lifecycle_events');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_number_counters');
    }
};
