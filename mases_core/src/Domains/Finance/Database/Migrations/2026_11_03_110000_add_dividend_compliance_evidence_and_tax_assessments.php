<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_dividend_distributions', function (Blueprint $table): void {
            $table->string('approved_balance_sheet_reference', 200)->nullable();
            $table->decimal('distributable_profit_amount', 20, 4)->nullable();
            $table->foreignId('profit_certified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('profit_certified_at')->nullable();
            $table->string('resolution_evidence_reference', 500)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->string('accounting_framework', 32)->nullable();
            $table->string('accrual_journal_reference', 100)->nullable();
            $table->string('withholding_journal_reference', 100)->nullable();
        });

        Schema::create('finance_dividend_tax_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->string('recipient_type', 32);
            $table->string('tax_residency', 32);
            $table->string('treatment', 32);
            $table->unsignedSmallInteger('withholding_rate_bps');
            $table->string('legal_basis', 500);
            $table->string('evidence_reference', 500)->nullable();
            $table->date('effective_from');
            $table->date('evidence_expires_on')->nullable();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assessed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'distribution_id', 'shareholder_id'], 'fdt_assessment_uq');
            $table->index(['tenant_id', 'shareholder_id', 'effective_from'], 'fdt_shareholder_effective_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dividend_tax_assessments');
        Schema::table('finance_dividend_distributions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profit_certified_by');
            $table->dropColumn([
                'approved_balance_sheet_reference', 'distributable_profit_amount',
                'profit_certified_at', 'resolution_evidence_reference', 'payment_method',
                'accounting_framework', 'accrual_journal_reference', 'withholding_journal_reference',
            ]);
        });
    }
};
