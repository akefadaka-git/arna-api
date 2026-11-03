<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_shareholder_dividend_payables', function (Blueprint $table): void {
            $table->decimal('reinvested_amount', 20, 4)->default(0)->after('penalty_offset_amount');
        });
        Schema::table('finance_dividend_reinvestments', function (Blueprint $table): void {
            $table->string('journal_reference', 100)->nullable();
            $table->string('seller_settlement_status', 24)->default('not_applicable');
            $table->string('seller_bank_reference', 150)->nullable();
            $table->string('seller_evidence_reference', 500)->nullable();
            $table->string('seller_journal_reference', 100)->nullable();
            $table->foreignId('seller_settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('seller_settled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_shareholder_dividend_payables', function (Blueprint $table): void {
            $table->dropColumn('reinvested_amount');
        });
        Schema::table('finance_dividend_reinvestments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seller_settled_by');
            $table->dropColumn([
                'journal_reference', 'seller_settlement_status', 'seller_bank_reference',
                'seller_evidence_reference', 'seller_journal_reference', 'seller_settled_at',
            ]);
        });
    }
};
