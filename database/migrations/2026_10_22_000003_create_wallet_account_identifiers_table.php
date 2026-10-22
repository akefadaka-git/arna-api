<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_account_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('wallet_id');
            $table->string('account_number', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'account_number'], 'wallet_account_identifiers_tenant_number_unique');
            $table->unique(['tenant_id', 'wallet_id'], 'wallet_account_identifiers_tenant_wallet_unique');
            $table->index(['tenant_id', 'wallet_id', 'is_active'], 'wallet_account_identifiers_lookup_index');

            $table->foreign(['tenant_id', 'wallet_id'], 'wallet_account_identifiers_tenant_wallet_fk')
                ->references(['tenant_id', 'id'])
                ->on('wallets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_account_identifiers');
    }
};
