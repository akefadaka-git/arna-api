<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_dividend_payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->string('batch_reference', 100);
            $table->char('currency', 3)->default('ETB');
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->string('status', 24)->default('draft');
            $table->string('reconciliation_status', 24)->default('pending');
            $table->string('bank_statement_reference', 200)->nullable();
            $table->decimal('bank_statement_amount', 20, 4)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'batch_reference'], 'fdiv_batch_ref_uq');
            $table->index(['tenant_id', 'distribution_id', 'status'], 'fdiv_batch_dist_status_ix');
        });

        Schema::create('finance_dividend_payment_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('finance_dividend_payment_batches')->restrictOnDelete();
            $table->foreignId('payable_id')->constrained('finance_shareholder_dividend_payables')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->string('status', 24)->default('pending');
            $table->string('bank_reference', 200)->nullable();
            $table->string('evidence_reference', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('journal_reference', 100)->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'batch_id', 'payable_id'], 'fdiv_batch_payable_uq');
            $table->unique(['tenant_id', 'bank_reference'], 'fdiv_bank_ref_uq');
            $table->index(['tenant_id', 'payable_id', 'status'], 'fdiv_payable_status_ix');
        });

        Schema::create('finance_dividend_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('finance_dividend_payment_batches')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 48);
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['tenant_id', 'batch_id', 'occurred_at'], 'fdiv_event_batch_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dividend_payment_events');
        Schema::dropIfExists('finance_dividend_payment_batch_items');
        Schema::dropIfExists('finance_dividend_payment_batches');
    }
};
