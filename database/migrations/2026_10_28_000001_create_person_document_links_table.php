<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('link_reason', 1000);
            $table->timestamps();

            $table->unique(['tenant_id', 'person_id', 'file_id'], 'person_document_links_tenant_person_file_unique');
            $table->index(['tenant_id', 'person_id', 'created_at'], 'person_document_links_profile_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_document_links');
    }
};
