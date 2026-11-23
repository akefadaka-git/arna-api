<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_device_login_challenges', function (Blueprint $table): void {
            $table->id();
            $table->char('challenge_hash', 64)->unique();
            $table->morphs('user');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->char('installation_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_type', 'user_id', 'expires_at'], 'auth_device_challenges_owner_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_device_login_challenges');
    }
};
