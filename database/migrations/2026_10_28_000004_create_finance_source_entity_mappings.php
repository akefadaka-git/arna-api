<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_source_entity_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'legal_entity_id'], 'finance_source_entity_mapping_entity_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->unique(
                ['tenant_id', 'source_type', 'source_id'],
                'finance_source_entity_mapping_source_unique'
            );
            $table->index(
                ['tenant_id', 'source_type', 'status'],
                'finance_source_entity_mapping_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_source_entity_mappings');
    }
};
