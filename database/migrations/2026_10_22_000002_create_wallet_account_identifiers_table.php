<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Account-number identity is intentionally separate from the Wallet
        // aggregate: the Wallet domain does not own a human-readable number.
        Schema::create('wallet_account_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->string('account_number', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'account_number'], 'wallet_account_identifiers_tenant_number_unique');
            $table->unique(['tenant_id', 'wallet_id'], 'wallet_account_identifiers_tenant_wallet_unique');
            $table->index(['tenant_id', 'wallet_id', 'is_active'], 'wallet_account_identifiers_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_account_identifiers');
    }
};
