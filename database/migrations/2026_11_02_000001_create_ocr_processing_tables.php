<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_processing_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('file_version');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);
            $table->string('languages', 32)->default('amh+eng');
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'ocr_job_idempotency_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'ocr_jobs_tenant_status_idx');
        });

        Schema::create('ocr_processing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('job_id')->constrained('ocr_processing_jobs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('event', 64);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'job_id', 'created_at'], 'ocr_events_tenant_job_idx');
        });

        Schema::create('ocr_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('job_id')->unique()->constrained('ocr_processing_jobs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('file_version');
            $table->string('engine_name', 64);
            $table->string('engine_version', 64);
            $table->string('languages', 32);
            $table->longText('full_text');
            $table->decimal('mean_confidence', 5, 2)->nullable();
            $table->char('source_checksum', 64);
            $table->char('result_checksum', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'file_id', 'file_version'], 'ocr_results_source_idx');
        });

        Schema::create('ocr_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('result_id')->constrained('ocr_results')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->longText('text');
            $table->decimal('mean_confidence', 5, 2)->nullable();
            $table->json('blocks')->nullable();
            $table->timestamps();
            $table->unique(['result_id', 'page_number']);
            $table->index(['tenant_id', 'result_id']);
        });

        Schema::create('ocr_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('result_id')->constrained('ocr_results')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('decision', 24);
            $table->text('notes')->nullable();
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->index(['tenant_id', 'result_id', 'verified_at']);
        });

        Schema::create('ocr_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('result_id')->constrained('ocr_results')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('corrected_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('page_number')->nullable();
            $table->longText('original_text');
            $table->longText('corrected_text');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_corrections');
        Schema::dropIfExists('ocr_processing_events');
        Schema::dropIfExists('ocr_verifications');
        Schema::dropIfExists('ocr_pages');
        Schema::dropIfExists('ocr_results');
        Schema::dropIfExists('ocr_processing_jobs');
    }
};
