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
            $table->foreignId('replaces_distribution_id')->nullable()
                ->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->string('replacement_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_dividend_distributions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaces_distribution_id');
            $table->dropColumn('replacement_reason');
        });
    }
};
