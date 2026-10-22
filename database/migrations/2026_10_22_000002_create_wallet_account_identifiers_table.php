<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The identifier's tenant must match the owning wallet's tenant at
        // the database layer, not only in the resolver's query.
        // Wallet IDs are globally unique already; this composite unique key
        // provides the referenced key required by the composite foreign key.
        Schema::table('wallets', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'wallets_tenant_id_id_unique');
        });

        Schema::create('wallet_account_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedBigInteger('wallet_id');
            $table->string('account_number', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['tenant_id', 'wallet_id'], 'wallet_account_identifiers_tenant_wallet_fk')
                ->references(['tenant_id', 'id'])
                ->on('wallets')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Identifiers are tenant-unique and not reusable, including
            // after soft deletion. Each wallet has one canonical identifier.
            $table->unique(['tenant_id', 'account_number'], 'wallet_account_identifiers_tenant_number_unique');
            $table->unique(['tenant_id', 'wallet_id'], 'wallet_account_identifiers_tenant_wallet_unique');
            $table->index(['tenant_id', 'wallet_id', 'is_active'], 'wallet_account_identifiers_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_account_identifiers');

        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropUnique('wallets_tenant_id_id_unique');
        });
    }
};
