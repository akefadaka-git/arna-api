<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * Tenant- and entity-scoped references from Finance records to the canonical
 * Documents domain. File binaries and document lifecycle remain Documents-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->unsignedBigInteger('document_id');
            $table->string('source_domain', 48);
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->string('purpose', 40);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('linked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('linked_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'legal_entity_id'], 'finance_document_links_entity_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'document_id'], 'finance_document_links_document_fk')
                ->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'document_id', 'source_domain', 'source_type', 'source_id', 'purpose'],
                'finance_document_links_identity_unique'
            );
            $table->index(['tenant_id', 'legal_entity_id', 'source_domain', 'source_type', 'source_id'], 'finance_document_links_source_idx');
            $table->index(['tenant_id', 'legal_entity_id', 'purpose', 'linked_at'], 'finance_document_links_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_links');
    }
};
