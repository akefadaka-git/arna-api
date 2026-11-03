<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_dividend_distribution_items', function (Blueprint $table): void {
            $table->decimal('weighted_share_quantity', 20, 4)->default(0);
        });

        Schema::create('finance_dividend_share_class_weights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->restrictOnDelete();
            $table->unsignedInteger('weight_bps');
            $table->string('evidence_reference', 500);
            $table->string('status', 16)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'distribution_id', 'share_class_id'], 'fdiv_class_weight_uq');
        });

        Schema::create('finance_dividend_distribution_share_class_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'fdiv_snap_tenant_fk')->restrictOnDelete();
            $table->foreignId('distribution_id')->constrained('finance_dividend_distributions', 'id', 'fdiv_snap_distribution_fk')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders', 'id', 'fdiv_snap_shareholder_fk')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes', 'id', 'fdiv_snap_class_fk')->restrictOnDelete();
            $table->decimal('eligible_share_quantity', 20, 4);
            $table->unsignedInteger('weight_bps');
            $table->decimal('weighted_share_quantity', 20, 4);
            $table->timestamps();
            $table->unique(['tenant_id', 'distribution_id', 'shareholder_id', 'share_class_id'], 'fdiv_class_snapshot_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_dividend_distribution_share_class_snapshots');
        Schema::dropIfExists('finance_dividend_share_class_weights');
        Schema::table('finance_dividend_distribution_items', function (Blueprint $table): void {
            $table->dropColumn('weighted_share_quantity');
        });
    }
};
