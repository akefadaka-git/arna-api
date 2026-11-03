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
    }

    public function down(): void
    {
        Schema::table('finance_shareholder_dividend_payables', function (Blueprint $table): void {
            $table->dropColumn('reinvested_amount');
        });
    }
};
