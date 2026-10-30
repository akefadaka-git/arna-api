<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_document_registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('registration_uuid');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('organization_unit_id');
            $table->char('bb_code', 2);
            $table->unsignedBigInteger('file_id');
            $table->unsignedInteger('file_version');
            $table->string('sender_name', 255)->nullable();
            // Deliberately stored verbatim; this is the sender's reference, not ARNA's ID.
            $table->string('sender_reference', 255)->nullable();
            $table->string('subject', 255);
            $table->text('notes')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('registered_at');
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'incoming_docs_tenant_id_unique');
            $table->unique(['tenant_id', 'registration_uuid'], 'incoming_docs_tenant_uuid_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'incoming_docs_idempotency_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'incoming_docs_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'incoming_docs_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->foreign('file_id', 'incoming_docs_file_fk')->references('id')->on('files')->restrictOnDelete();
            $table->index(['tenant_id', 'received_at'], 'incoming_docs_tenant_received_idx');
            $table->index(['tenant_id', 'sender_reference'], 'incoming_docs_sender_ref_idx');
        });

        Schema::create('incoming_document_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('incoming_document_registration_id');
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign(['tenant_id', 'incoming_document_registration_id'], 'incoming_doc_events_registration_tenant_fk')
                ->references(['tenant_id', 'id'])->on('incoming_document_registrations')->restrictOnDelete();
            $table->index(['tenant_id', 'incoming_document_registration_id', 'occurred_at'], 'incoming_doc_events_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_document_events');
        Schema::dropIfExists('incoming_document_registrations');
    }
};
