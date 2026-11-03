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
            $table->boolean('is_historical')->default(false);
            $table->boolean('historical_timing_exception')->default(false);
            $table->string('historical_source_category', 40)->nullable();
            $table->string('historical_evidence_reference', 500)->nullable();
            $table->string('historical_import_reason', 500)->nullable();
            $table->dateTime('historical_imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_dividend_distributions', function (Blueprint $table): void {
            $table->dropColumn([
                'is_historical', 'historical_timing_exception', 'historical_source_category',
                'historical_evidence_reference', 'historical_import_reason',
                'historical_imported_at',
            ]);
        });
    }
};
