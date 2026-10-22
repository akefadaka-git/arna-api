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
            $table->timestamp('phone_verified_at')
                ->nullable()
                ->comment('Phone number verification timestamp');
            $table->char('phone_verified_phone_hash', 64)
                ->nullable()
                ->comment('SHA-256 of the exact phone value verified');
        });

        Schema::create('phone_verification_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('phone', 20);
            $table->string('code_hash', 255);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at'], 'phone_verification_user_consumed_idx');
            $table->index('expires_at', 'phone_verification_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_challenges');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone_verified_at', 'phone_verified_phone_hash']);
        });
    }
};
