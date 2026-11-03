<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_dividend_tax_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->string('remittance_reference', 100);
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3)->default('ETB');
            $table->string('status', 16)->default('draft');
            $table->string('tax_authority_reference', 200)->nullable();
            $table->string('bank_reference', 200)->nullable();
            $table->string('evidence_reference', 500)->nullable();
            $table->string('journal_reference', 100)->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'remittance_reference'], 'fdiv_tax_remit_ref_uq');
            $table->unique(['tenant_id', 'bank_reference'], 'fdiv_tax_remit_bank_ref_uq');
            $table->index(['tenant_id', 'distribution_id', 'status'], 'fdiv_tax_remit_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dividend_tax_remittances');
    }
};
