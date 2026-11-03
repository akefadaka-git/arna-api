<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_dividend_tax_assessments', function (Blueprint $table): void {
            $table->string('tax_certificate_number', 100)->nullable();
            $table->dateTime('certificate_issued_at')->nullable();
            $table->string('certificate_status', 16)->default('pending');
            $table->unique(['tenant_id', 'tax_certificate_number'], 'fdt_certificate_number_uq');
        });
    }

    public function down(): void
    {
        Schema::table('finance_dividend_tax_assessments', function (Blueprint $table): void {
            $table->dropUnique('fdt_certificate_number_uq');
            $table->dropColumn(['tax_certificate_number', 'certificate_issued_at', 'certificate_status']);
        });
    }
};
