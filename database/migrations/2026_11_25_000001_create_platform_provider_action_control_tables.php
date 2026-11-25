<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_provider_action_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider_key', 100);
            $table->string('adapter_version', 40);
            $table->string('environment', 32);
            $table->string('operation_key', 100);
            $table->string('resource_type', 80);
            $table->string('resource_identifier', 255);
            $table->json('plan_payload');
            $table->char('payload_sha256', 64);
            $table->string('risk_tier', 24);
            $table->string('policy_version', 40);
            $table->unsignedBigInteger('requester_id');
            $table->string('reason', 1000);
            $table->string('status', 32)->default('pending_approval');
            $table->unsignedSmallInteger('required_approvals')->default(2);
            $table->boolean('owner_signature_required')->default(false);
            $table->timestamp('owner_signature_verified_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at'], 'ppar_status_expiry_idx');
            $table->index(['provider_key', 'environment'], 'ppar_provider_env_idx');
            $table->index(['requester_id', 'created_at'], 'ppar_requester_created_idx');
        });

        Schema::create('platform_provider_action_approvals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('approver_id');
            $table->string('decision', 16);
            $table->char('payload_sha256', 64);
            $table->string('policy_version', 40);
            $table->string('note', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['request_id', 'approver_id'], 'ppaa_request_approver_uq');
            $table->index(['request_id', 'decision'], 'ppaa_request_decision_idx');
            $table->foreign('request_id', 'ppaa_request_fk')
                ->references('id')->on('platform_provider_action_requests')->cascadeOnDelete();
        });

        Schema::create('platform_provider_action_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 80);
            $table->char('payload_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['request_id', 'created_at'], 'ppaae_request_created_idx');
            $table->index(['event_type', 'created_at'], 'ppaae_event_created_idx');
        });

        Schema::create('platform_provider_action_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id')->unique('ppao_request_uq');
            $table->string('idempotency_key', 100)->unique('ppao_idempotency_uq');
            $table->char('payload_sha256', 64);
            $table->string('status', 24)->default('held');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('sanitized_result')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->foreign('request_id', 'ppao_request_fk')
                ->references('id')->on('platform_provider_action_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_provider_action_outbox');
        Schema::dropIfExists('platform_provider_action_audit_events');
        Schema::dropIfExists('platform_provider_action_approvals');
        Schema::dropIfExists('platform_provider_action_requests');
    }
};
