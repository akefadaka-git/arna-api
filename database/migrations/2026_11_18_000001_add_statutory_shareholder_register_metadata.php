<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        if (DB::table('shareholders')
            ->whereNotNull('registered_address')
            ->orWhereNotNull('register_entry_date')
            ->orWhereNotNull('register_source_reference')
            ->orWhere('register_source_type', '<>', 'legacy_unknown')
            ->orWhere('register_verification_status', '<>', 'unverified')
            ->orWhereNotNull('register_verified_at')
            ->orWhereNotNull('register_verified_by')
            ->exists()) {
            throw new RuntimeException(
                'Cannot roll back statutory shareholder-register metadata while source or verification data exists.'
            );
        }

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
