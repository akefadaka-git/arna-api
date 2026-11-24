<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('fqdn', 253)->unique();
            $table->string('owner_type', 24)->default('platform');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('display_name', 160)->nullable();
            $table->string('environment', 24)->default('production');
            $table->string('lifecycle_status', 32)->default('pending_verification');
            $table->timestamp('ownership_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
            $table->index(['lifecycle_status', 'environment']);
        });

        Schema::create('platform_domain_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('domain_id')->index();
            $table->string('method', 24)->default('dns_txt');
            $table->string('challenge_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->json('evidence')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['domain_id', 'verified_at']);
        });

        Schema::create('platform_domain_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('domain_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 64);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['domain_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_domain_audit_events');
        Schema::dropIfExists('platform_domain_verifications');
        Schema::dropIfExists('platform_domains');
    }
};
