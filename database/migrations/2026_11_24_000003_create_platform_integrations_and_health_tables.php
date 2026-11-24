<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 100)->unique();
            $table->string('provider_type', 40);
            $table->string('display_name', 160);
            $table->string('category', 40);
            $table->string('endpoint_url', 2048)->nullable();
            $table->string('auth_mode', 40)->default('none');
            $table->longText('credential_ciphertext')->nullable();
            $table->string('connection_state', 32)->default('not_connected');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['category', 'connection_state']);
        });

        Schema::create('platform_health_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('component_key', 120);
            $table->string('component_type', 40);
            $table->string('status', 32);
            $table->timestamp('checked_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('evidence')->nullable();
            $table->string('source', 40)->default('probe');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['component_key', 'checked_at']);
            $table->index(['component_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_health_observations');
        Schema::dropIfExists('platform_integrations');
    }
};
