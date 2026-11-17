<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->morphs('user');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->unique()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->char('installation_hash', 64);
            $table->string('platform', 24)->default('android');
            $table->string('app_version', 40)->nullable();
            $table->string('device_label', 100)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 64)->nullable();
            $table->timestamps();

            $table->index(
                ['user_type', 'user_id', 'revoked_at'],
                'auth_device_sessions_user_active_idx'
            );
            $table->index(
                ['user_type', 'user_id', 'installation_hash'],
                'auth_device_sessions_installation_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_device_sessions');
    }
};
