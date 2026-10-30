<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->boolean('is_control_account')->default(false)->after('is_posting_allowed');
            $table->string('control_domain', 80)->nullable()->after('is_control_account');
            $table->string('default_tax_category', 24)->default('undetermined')->after('control_domain');
            $table->string('coa_version', 16)->nullable()->after('default_tax_category');
            $table->boolean('is_system_account')->default(false)->after('coa_version');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'is_control_account', 'control_domain', 'default_tax_category',
                'coa_version', 'is_system_account',
            ]);
        });
    }
};
