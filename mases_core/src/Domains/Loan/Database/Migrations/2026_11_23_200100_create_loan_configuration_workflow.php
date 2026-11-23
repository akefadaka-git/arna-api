<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false)->change();
        });

        // Existing catalogue entries must be explicitly reviewed and
        // activated through the approval workflow. No product is deleted.
        DB::table('loan_products')->update(['is_active' => false]);

        Schema::create('loan_configuration_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('published_product_id')->nullable()->constrained('loan_products')->restrictOnDelete();
            $table->foreignId('published_policy_version_id')->nullable()->constrained('versioned_policies')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->json('configuration');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('loan_configuration_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('loan_configuration_submissions')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_configuration_audit_logs');
        Schema::dropIfExists('loan_configuration_submissions');
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->change();
        });
    }
};
