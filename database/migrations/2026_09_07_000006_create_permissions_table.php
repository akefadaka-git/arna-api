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
     * Creates the `permissions` table for the MASES RBAC system.
     * Permissions are atomic authorization units with a scope: 'global', 'tenant', or 'application'.
     * Display names and descriptions are stored in both Amharic and English to support the Amharic‑first design.
     *
     * ይህ ማይግሬሽን ለMASES የተግባር ፈቃድ ሥርዓት `permissions` ሠንጠረዥ ይፈጥራል።
     * ፈቃዶች 'global'፣ 'tenant'፣ ወይም 'application' የሚባል ወሰን አላቸው።
     * የማሳያ ስሞች እና መግለጫዎች በሁለቱም በአማርኛ እና በእንግሊዝኛ ተቀምጠዋል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            // Primary identifier.
            $table->id();

            // Bilingual display names.
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Permission name in Amharic');
            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Permission name in English');

            // Unique slug – globally unique, used in authorization checks.
            $table->string('slug', 150)
                  ->unique()
                  ->comment('Unique permission slug (e.g., finance.transaction.create)');

            // Bilingual descriptions.
            $table->string('description_am', 255)
                  ->nullable()
                  ->comment('Permission description in Amharic');
            $table->string('description_en', 255)
                  ->nullable()
                  ->comment('Permission description in English');

            // Category for grouping (not translated).
            $table->string('category', 100)
                  ->nullable()
                  ->comment('Permission category (e.g., finance, hr)');

            // Scope: 'global', 'tenant', or 'application'.
            $table->enum('scope', ['global', 'tenant', 'application'])
                  ->default('global')
                  ->comment('Permission scope');

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
                  ->comment('Whether the permission is active');

            // System flag – critical permissions cannot be deleted.
            $table->boolean('is_system')
                  ->default(false)
                  ->comment('Whether the permission is system-critical');

            // Soft delete.
            $table->softDeletes()
                  ->comment('Soft delete timestamp');

            // Standard timestamps.
            $table->timestamps();

            // Indexes for performance (slug already indexed via unique).
            $table->index('category');
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
        Schema::dropIfExists('permissions');
    }
};