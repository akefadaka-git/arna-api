<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_source_entity_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('legal_entity_id');
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('mapping_reason');
            $table->timestamps();

            // One originating record has one authoritative Finance entity mapping
            // within its tenant. Reassignment must be an explicit audited change,
            // never an additional competing active mapping.
            $table->unique(
                ['tenant_id', 'source_type', 'source_id'],
                'journal_source_entity_mapping_source_unique'
            );
            $table->foreign(
                ['tenant_id', 'legal_entity_id'],
                'journal_source_entity_mapping_entity_fk'
            )->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();

            $table->index(
                ['tenant_id', 'legal_entity_id', 'source_type', 'status'],
                'journal_source_entity_mapping_scope_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_source_entity_mappings');
    }
};
