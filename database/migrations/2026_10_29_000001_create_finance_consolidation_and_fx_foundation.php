<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_consolidation_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('ledger_account_id');
            $table->string('consolidation_account_code', 64);
            $table->string('account_type', 24);
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('mapping_reason');
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'ledger_account_id'], 'finance_consolidation_mapping_account_unique');
            $table->foreign(['tenant_id', 'legal_entity_id', 'ledger_account_id'], 'finance_consolidation_mapping_account_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'status', 'consolidation_account_code'], 'finance_consolidation_mapping_lookup_index');
        });

        Schema::create('finance_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 24, 10);
            $table->date('effective_on');
            $table->string('source', 120);
            $table->string('source_reference', 160)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('proposal_reason');
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'base_currency', 'quote_currency', 'effective_on'], 'finance_exchange_rate_pair_date_unique');
            $table->index(['tenant_id', 'base_currency', 'quote_currency', 'status', 'effective_on'], 'finance_exchange_rate_resolution_index');
            $table->index(['tenant_id', 'status', 'effective_on'], 'finance_exchange_rate_review_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_exchange_rates');
        Schema::dropIfExists('finance_consolidation_account_mappings');
    }
};
