<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name_am', 180)->nullable();
            $table->string('name_en', 180)->nullable();
            $table->char('base_currency', 3)->default('ETB');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'legal_entities_tenant_code_unique');
            $table->unique(['tenant_id', 'id'], 'legal_entities_tenant_id_id_unique');
            $table->index(['tenant_id', 'status'], 'legal_entities_tenant_status_index');
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('parent_id')->nullable();
            $table->string('code', 64);
            $table->string('name_am', 180)->nullable();
            $table->string('name_en', 180);
            $table->string('account_type', 24);
            $table->string('normal_balance', 8);
            $table->boolean('is_posting_allowed')->default(true);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'ledger_accounts_entity_code_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'ledger_accounts_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'ledger_accounts_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'parent_id'], 'ledger_accounts_parent_same_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'account_type', 'status'], 'ledger_accounts_type_status_index');
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->string('code', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'accounting_periods_entity_code_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'accounting_periods_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'accounting_periods_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'starts_on', 'ends_on'], 'accounting_periods_dates_index');
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('accounting_period_id');
            $table->string('journal_number', 64);
            $table->date('effective_date');
            $table->string('status', 16)->default('draft');
            $table->string('currency', 3)->default('ETB');
            $table->string('description', 500);
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('idempotency_key', 128);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'journal_number'], 'journal_entries_entity_number_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'idempotency_key'], 'journal_entries_idempotency_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'journal_entries_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'journal_entries_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'accounting_period_id'], 'journal_entries_period_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'reversal_of_id'], 'journal_entries_reversal_same_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'status', 'effective_date'], 'journal_entries_ledger_index');
            $table->index(['tenant_id', 'legal_entity_id', 'source_type', 'source_id'], 'journal_entries_source_index');
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('journal_entry_id');
            $table->foreignId('account_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500)->nullable();
            $table->decimal('debit_amount', 20, 4)->default(0);
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'journal_entry_id', 'line_number'], 'journal_lines_entry_line_unique');
            $table->foreign(['tenant_id', 'legal_entity_id', 'journal_entry_id'], 'journal_lines_entry_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'account_id'], 'journal_lines_account_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'account_id', 'journal_entry_id'], 'journal_lines_account_ledger_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('legal_entities');
    }
};
