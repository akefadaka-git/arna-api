<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shareholder_import_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('status', 32)->index();
            $table->json('payload');
            $table->json('source_references');
            $table->unsignedInteger('row_count');
            $table->char('payload_sha256', 64);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('submitted_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedInteger('reviewed_row_count')->default(0);
            $table->text('review_note')->nullable();
            $table->json('review_evidence')->nullable();
            $table->string('approval_authority_reference', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'id'], 'shareholder_import_tenant_status_idx');
            $table->index(['tenant_id', 'id'], 'shareholder_import_tenant_id_idx');
        });

        Schema::create('shareholder_import_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('submission_id')
                ->constrained('shareholder_import_submissions')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('decision_type', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('note')->nullable();
            $table->string('authority_reference', 255)->nullable();
            $table->json('source_references');
            $table->json('evidence')->nullable();
            $table->char('payload_sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['submission_id', 'sequence'], 'shareholder_import_decision_sequence_uq');
            $table->index(['tenant_id', 'submission_id'], 'shareholder_import_decision_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholder_import_decisions');
        Schema::dropIfExists('shareholder_import_submissions');
    }
};
