<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_dividend_distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->string('distribution_reference', 100);
            $table->string('financial_year', 32);
            $table->string('resolution_reference', 150);
            $table->date('resolution_date');
            $table->date('declared_on');
            $table->date('record_date');
            $table->date('payment_due_on');
            $table->char('currency', 3)->default('ETB');
            $table->decimal('declared_amount', 20, 4);
            $table->string('allocation_method', 32)->default('pro_rata_shares');
            $table->unsignedSmallInteger('withholding_rate_bps')->default(0);
            $table->string('status', 24)->default('draft');
            $table->unsignedBigInteger('snapshot_row_count')->default(0);
            $table->decimal('eligible_share_quantity', 20, 4)->default(0);
            $table->decimal('gross_total', 20, 4)->default(0);
            $table->decimal('withholding_total', 20, 4)->default(0);
            $table->decimal('net_total', 20, 4)->default(0);
            $table->char('snapshot_sha256', 64)->nullable();
            $table->dateTime('snapshot_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'distribution_reference'], 'fdd_reference_uq');
            $table->index(['tenant_id', 'legal_entity_id', 'financial_year', 'status'], 'fdd_year_status_ix');
        });

        Schema::create('finance_dividend_distribution_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->decimal('eligible_share_quantity', 20, 4);
            $table->decimal('gross_amount', 20, 4);
            $table->decimal('withholding_amount', 20, 4);
            $table->decimal('net_amount', 20, 4);
            $table->foreignId('payable_id')->nullable()->constrained('finance_shareholder_dividend_payables')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'distribution_id', 'shareholder_id'], 'fddi_distribution_shareholder_uq');
            $table->index(['tenant_id', 'shareholder_id', 'distribution_id'], 'fddi_shareholder_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dividend_distribution_items');
        Schema::dropIfExists('finance_dividend_distributions');
    }
};
