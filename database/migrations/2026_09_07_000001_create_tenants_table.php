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
     * Creates the foundational `tenants` table – the root of the MASES
     * multi‑tenancy architecture. Tenant display names are stored in both
     * Amharic and English to support the Amharic‑first design with locale
     * switching.
     *
     * ይህ ማይግሬሽን የMASES ባለብዙ‑ተከራይ ሥነ‑ሕንጻ መሠረት የሆነውን `tenants` ሠንጠረዥ ይፈጥራል።
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            // Primary identifier.
            $table->id();

            // Bilingual tenant names (at least one should be set).
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Tenant name in Amharic');
            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Tenant name in English');

            // Unique slug used for subdomain/route identification.
            $table->string('slug', 100)
                  ->unique()
                  ->comment('Unique, URL‑safe identifier for the tenant');

            // Optional primary domain for subdomain‑based resolution.
            $table->string('domain', 255)
                  ->nullable()
                  ->unique()
                  ->comment('Primary domain (e.g., tenant.example.com)');

            // Flexible JSON field for tenant‑specific configuration.
            $table->json('settings')
                  ->nullable()
                  ->comment('Tenant‑specific configuration (JSON)');

            // Active flag – inactive tenants are excluded from normal queries.
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the tenant is active');

            // Soft delete support.
            $table->softDeletes()
                  ->comment('Timestamp when tenant was archived (soft delete)');

            // Standard timestamps.
            $table->timestamps();

            // Indexes for performance.
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
        Schema::dropIfExists('tenants');
    }
};