<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meeting_legal_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->string('legal_form', 32);
            $table->string('meeting_category', 24);
            $table->string('source_reference', 2000);
            $table->char('source_sha256', 64);
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_id'], 'gmlb_tenant_meeting_uq');
            $table->index(['tenant_id', 'status'], 'gmlb_tenant_status_ix');
        });

        Schema::create('governance_meeting_legal_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_basis_id')->constrained('governance_meeting_legal_bases')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('evidence_key', 64);
            $table->string('source_reference', 2000);
            $table->char('source_sha256', 64);
            $table->json('rule_parameters')->nullable();
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['legal_basis_id', 'evidence_key'], 'gmle_basis_evidence_uq');
            $table->index(['tenant_id', 'legal_basis_id', 'action', 'status'], 'gmle_tenant_action_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_legal_evidence');
        Schema::dropIfExists('governance_meeting_legal_bases');
    }
};
