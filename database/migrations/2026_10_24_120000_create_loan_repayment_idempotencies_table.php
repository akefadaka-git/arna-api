<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the idempotency contract for a single tenant-scoped loan repayment.
 *
 * The unique scope (tenant, loan, key) serializes concurrent retries. The
 * request hash prevents a caller from reusing a key for a different payload.
 * The row and its payment reference are written in the same transaction as
 * the wallet payment, allocations, and loan projections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_repayment_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('loan_id')
                ->constrained('loans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('wallet_payments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'loan_id', 'idempotency_key'],
                'lrp_idempotency_scope_key_unique',
            );
            $table->index('payment_id', 'lrp_idempotency_payment_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayment_idempotencies');
    }
};
