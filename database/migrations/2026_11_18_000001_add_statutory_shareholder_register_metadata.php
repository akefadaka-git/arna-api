<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->text('registered_address')->nullable();
            $table->date('register_entry_date')->nullable();
            $table->string('register_source_type', 32)->default('legacy_unknown');
            $table->string('register_source_reference', 255)->nullable();
            $table->string('register_verification_status', 24)->default('unverified');
            $table->timestamp('register_verified_at')->nullable();
            $table->foreignId('register_verified_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index(
                ['tenant_id', 'register_verification_status'],
                'shareholders_tenant_register_verification_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->dropIndex('shareholders_tenant_register_verification_idx');
            $table->dropForeign(['register_verified_by']);
            $table->dropColumn([
                'registered_address',
                'register_entry_date',
                'register_source_type',
                'register_source_reference',
                'register_verification_status',
                'register_verified_at',
                'register_verified_by',
            ]);
        });
    }
};
