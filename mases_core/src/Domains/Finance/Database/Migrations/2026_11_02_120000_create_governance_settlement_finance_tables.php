<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance-owned configuration and authoritative payable source for Governance
 * meeting-penalty settlement. No account IDs or balances are seeded: each
 * legal entity must be configured and approved by Finance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_settlement_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->string('account_role', 32);
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('configuration_reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'legal_entity_id', 'account_role', 'status'], 'gsam_entity_role_status_ix');
        });

        Schema::create('finance_shareholder_dividend_payables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->string('distribution_reference', 100);
            $table->date('declared_on');
            $table->date('due_on');
            $table->decimal('net_payable_amount', 20, 4);
            $table->decimal('cash_paid_amount', 20, 4)->default(0);
            $table->decimal('penalty_offset_amount', 20, 4)->default(0);
            $table->string('status', 16)->default('draft');
            $table->text('approval_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'shareholder_id', 'distribution_reference'], 'fsdp_distribution_shareholder_uq');
            $table->index(['tenant_id', 'legal_entity_id', 'shareholder_id', 'status', 'due_on'], 'fsdp_payable_lookup_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_shareholder_dividend_payables');
        Schema::dropIfExists('governance_settlement_account_mappings');
    }
};
