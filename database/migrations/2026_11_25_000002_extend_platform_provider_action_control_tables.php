<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_provider_action_requests', function (Blueprint $table): void {
            $table->string('owner_signature_key_id', 120)->nullable();
            $table->char('owner_signature_sha256', 64)->nullable();
        });

        Schema::table('platform_provider_action_outbox', function (Blueprint $table): void {
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->string('reconciliation_status', 32)->nullable();
            $table->index(['status', 'available_at'], 'ppao_status_available_idx');
            $table->index(['status', 'lease_expires_at'], 'ppao_status_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_provider_action_outbox', function (Blueprint $table): void {
            $table->dropIndex('ppao_status_available_idx');
            $table->dropIndex('ppao_status_lease_idx');
            $table->dropColumn(['lease_token', 'lease_expires_at', 'last_error_code', 'reconciled_at', 'reconciliation_status']);
        });

        Schema::table('platform_provider_action_requests', function (Blueprint $table): void {
            $table->dropColumn(['owner_signature_key_id', 'owner_signature_sha256']);
        });
    }
};
