<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monetary share-subscription balances are separate from the
        // quantity-only ShareHolding projection and its append-only ledger.
        Schema::create('finance_share_subscription_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->restrictOnDelete();
            $table->string('subscription_reference', 120);
            $table->unsignedInteger('subscribed_share_quantity');
            $table->decimal('price_per_share', 20, 4);
            $table->decimal('original_amount', 20, 4);
            $table->decimal('outstanding_amount', 20, 4);
            $table->date('occurred_on');
            $table->string('evidence_reference', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'subscription_reference'], 'fssb_ref_uq');
            $table->index(['tenant_id', 'shareholder_id', 'outstanding_amount'], 'fssb_holder_open_ix');
        });

        Schema::create('finance_dividend_reinvestments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->foreignId('distribution_item_id')->constrained('finance_dividend_distribution_items')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->string('settlement_type', 40);
            $table->string('source_category', 40)->default('internal_revenue');
            $table->decimal('entitlement_amount', 20, 4);
            $table->decimal('applied_amount', 20, 4);
            $table->decimal('unapplied_amount', 20, 4)->default(0);
            $table->foreignId('subscription_balance_id')->nullable()->constrained('finance_share_subscription_balances')->restrictOnDelete();
            $table->foreignId('share_class_id')->nullable()->constrained('share_classes')->restrictOnDelete();
            $table->foreignId('seller_shareholder_id')->nullable()->constrained('shareholders')->restrictOnDelete();
            $table->unsignedInteger('share_quantity')->nullable();
            $table->decimal('price_per_share', 20, 4)->nullable();
            $table->unsignedBigInteger('buyer_holding_entry_id')->nullable();
            $table->unsignedBigInteger('seller_holding_entry_id')->nullable();
            $table->date('occurred_on');
            $table->string('transaction_reference', 150);
            $table->string('evidence_reference', 500);
            $table->string('idempotency_key', 100);
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'fdr_idempotency_uq');
            $table->unique(['tenant_id', 'transaction_reference'], 'fdr_reference_uq');
            $table->index(['tenant_id', 'shareholder_id', 'status'], 'fdr_holder_status_ix');
            $table->index(['tenant_id', 'distribution_item_id', 'status'], 'fdr_item_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dividend_reinvestments');
        Schema::dropIfExists('finance_share_subscription_balances');
    }
};
