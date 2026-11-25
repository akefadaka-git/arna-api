<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meeting_lifecycle_attestations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('legal_basis_id')->constrained('governance_meeting_legal_bases')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('source_reference', 2000);
            $table->char('source_sha256', 64);
            $table->json('details')->nullable();
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_id', 'action', 'status'], 'gmla_tenant_meeting_action_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_lifecycle_attestations');
    }
};
