<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_integrations', function (Blueprint $table): void {
            $table->string('environment', 32)->default('sandbox');
            $table->string('adapter_version', 40)->nullable();
            $table->boolean('sandbox_mutations_enabled')->default(false);
            $table->timestamp('sandbox_certified_at')->nullable();
            $table->string('sandbox_certification_reference', 160)->nullable();
            $table->index(['environment', 'sandbox_mutations_enabled'], 'pi_env_sandbox_mutation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_integrations', function (Blueprint $table): void {
            $table->dropIndex('pi_env_sandbox_mutation_idx');
            $table->dropColumn([
                'environment',
                'adapter_version',
                'sandbox_mutations_enabled',
                'sandbox_certified_at',
                'sandbox_certification_reference',
            ]);
        });
    }
};
