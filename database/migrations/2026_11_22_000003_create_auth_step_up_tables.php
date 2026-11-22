<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_step_up_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('action', 100);
            $table->string('channel', 16);
            $table->char('challenge_hash', 64)->unique();
            $table->char('context_hash', 64);
            $table->string('code_hash', 255);
            $table->timestamp('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'action', 'consumed_at'], 'auth_step_up_challenges_owner_action_idx');
        });

        Schema::create('auth_step_up_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('action', 100);
            $table->char('grant_hash', 64)->unique();
            $table->char('context_hash', 64);
            $table->json('verified_factor_groups');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'action', 'consumed_at'], 'auth_step_up_grants_owner_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_step_up_grants');
        Schema::dropIfExists('auth_step_up_challenges');
    }
};
