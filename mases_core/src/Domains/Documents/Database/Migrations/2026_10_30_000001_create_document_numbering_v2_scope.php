<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_counters_v2', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->foreignId('organization_unit_id');
            $table->char('bb_code', 2);
            $table->char('document_type_number', 2);
            $table->unsignedSmallInteger('ec_year');
            $table->unsignedInteger('next_sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'organization_unit_id', 'document_type_number', 'ec_year'],
                'doc_counter_v2_scope_unique'
            );
            $table->foreign(['tenant_id', 'legal_entity_id'], 'doc_counter_v2_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'doc_counter_v2_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->index(['tenant_id', 'is_active'], 'doc_counter_v2_tenant_active_idx');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->char('bb_code', 2)->nullable()->after('organization_code');
            $table->char('document_type_number', 2)->nullable()->after('document_type_code');
            $table->unsignedBigInteger('replaces_document_id')->nullable()->after('canonical_number');
            $table->unique(['tenant_id', 'id'], 'documents_tenant_id_unique');
            $table->unique(['tenant_id', 'replaces_document_id'], 'documents_single_replacement_unique');
            $table->foreign(['tenant_id', 'replaces_document_id'], 'documents_replaces_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
            $table->dropForeign(['legal_entity_id']);
            $table->dropForeign(['organization_unit_id']);
            $table->foreign(['tenant_id', 'legal_entity_id'], 'documents_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_unit_id'], 'documents_unit_tenant_fk')
                ->references(['tenant_id', 'id'])->on('organization_units')->restrictOnDelete();
            $table->unique(
                ['tenant_id', 'legal_entity_id', 'organization_unit_id', 'document_type_number', 'ec_year', 'sequence_number'],
                'documents_number_v2_scope_unique'
            );
        });

        Schema::table('document_lifecycle_events', function (Blueprint $table): void {
            $table->dropForeign('document_lifecycle_document_fk');
            $table->foreign(['tenant_id', 'document_id'], 'document_lifecycle_document_tenant_fk')
                ->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_lifecycle_events', function (Blueprint $table): void {
            $table->dropForeign('document_lifecycle_document_tenant_fk');
            $table->foreign('document_id', 'document_lifecycle_document_fk')
                ->references('id')->on('documents')->restrictOnDelete();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('documents_number_v2_scope_unique');
            $table->dropForeign('documents_replaces_same_tenant_fk');
            $table->dropUnique('documents_single_replacement_unique');
            $table->dropUnique('documents_tenant_id_unique');
            $table->dropColumn('replaces_document_id');
            $table->dropForeign('documents_entity_tenant_fk');
            $table->dropForeign('documents_unit_tenant_fk');
            $table->foreign('legal_entity_id')->references('id')->on('legal_entities')->restrictOnDelete();
            $table->foreign('organization_unit_id')->references('id')->on('organization_units')->restrictOnDelete();
            $table->dropColumn(['bb_code', 'document_type_number']);
        });
        Schema::dropIfExists('document_number_counters_v2');
    }
};
