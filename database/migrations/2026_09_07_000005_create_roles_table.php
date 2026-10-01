<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `roles` table for the MASES RBAC system.
     * Roles have a scope: 'platform', 'tenant', or 'application'.
     * Display names and descriptions are stored in both Amharic and English
     * to support the Amharic‑first design with locale switching.
     *
     * ይህ ማይግሬሽን ለMASES የተግባር ፈቃድ ሥርዓት `roles` ሠንጠረዥ ይፈጥራል።
     * ሚናዎች 'platform'፣ 'tenant'፣ ወይም 'application' የሚባል ወሰን አላቸው።
     * የማሳያ ስሞች እና መግለጫዎች በሁለቱም በአማርኛ እና በእንግሊዝኛ ተቀምጠዋል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            // Primary identifier.
            $table->id();

            // Bilingual display names.
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Role name in Amharic');
            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Role name in English');

            // Unique slug – globally unique for simplicity.
            $table->string('slug', 100)
                  ->unique()
                  ->comment('Unique role slug');

            // Bilingual descriptions.
            $table->string('description_am', 255)
                  ->nullable()
                  ->comment('Role description in Amharic');
            $table->string('description_en', 255)
                  ->nullable()
                  ->comment('Role description in English');

            // Scope: 'platform', 'tenant', or 'application'.
            $table->enum('scope', ['platform', 'tenant', 'application'])
                  ->default('platform')
                  ->comment('Role scope');

            // Foreign key to tenants – required if scope is 'tenant'.
            $table->foreignId('tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Tenant ID if scope = tenant');

            // Foreign key to platform_applications – required if scope is 'application'.
            $table->foreignId('application_id')
                  ->nullable()
                  ->constrained('platform_applications')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Application ID if scope = application');

            // Active flag.
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the role is active');

            // Soft delete.
            $table->softDeletes()
                  ->comment('Soft delete timestamp');

            // Standard timestamps.
            $table->timestamps();

            // Indexes for performance.
            // slug is already indexed via unique constraint.
            $table->index('scope');
            $table->index('tenant_id');
            $table->index('application_id');
            $table->index('is_active');
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};