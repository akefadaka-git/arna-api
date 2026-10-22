<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('totp_secret')->nullable();
            $table->text('totp_pending_secret')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('code_hash', 255);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'used_at'], 'mfa_recovery_user_used_idx');
        });

        Schema::create('login_mfa_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->boolean('remember')->default(false);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'consumed_at'], 'login_mfa_user_consumed_idx');
            $table->index('expires_at', 'login_mfa_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_mfa_challenges');
        Schema::dropIfExists('mfa_recovery_codes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['totp_secret', 'totp_pending_secret', 'totp_enabled_at']);
        });
    }
};
