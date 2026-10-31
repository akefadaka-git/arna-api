<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('category', 64);
            $table->string('priority', 16)->default('normal');
            $table->json('title');
            $table->json('body')->nullable();
            $table->string('action_route_name', 160)->nullable();
            $table->json('resource_ref')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at'], 'platform_notifications_inbox_idx');
            $table->unique(['tenant_id', 'user_id', 'dedupe_key'], 'platform_notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notifications');
    }
};
