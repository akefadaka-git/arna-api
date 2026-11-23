<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_configuration_submissions', function (Blueprint $table): void {
            $table->foreignId('revision_product_id')->nullable()
                ->after('tenant_id')->constrained('loan_products')->restrictOnDelete();
            $table->unsignedBigInteger('previous_policy_version_id')->nullable()
                ->after('revision_product_id');
            $table->foreign('previous_policy_version_id', 'loan_cfg_previous_policy_fk')
                ->references('id')->on('versioned_policies')->restrictOnDelete();
            $table->index(['tenant_id', 'revision_product_id'], 'loan_cfg_revision_product_idx');
        });
    }

    public function down(): void
    {
        Schema::table('loan_configuration_submissions', function (Blueprint $table): void {
            $table->dropIndex('loan_cfg_revision_product_idx');
            $table->dropForeign('loan_cfg_previous_policy_fk');
            $table->dropConstrainedForeignId('revision_product_id');
            $table->dropColumn('previous_policy_version_id');
        });
    }
};
