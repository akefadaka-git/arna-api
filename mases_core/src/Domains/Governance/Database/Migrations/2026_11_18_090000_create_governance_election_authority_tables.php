<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_elections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('governance_body_id')->nullable()->constrained('governance_bodies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('title', 255);
            $table->string('status', 24)->default('planned')->index();
            $table->string('voting_method', 32)->nullable();
            $table->string('authority_status', 24)->default('unverified')->index();
            $table->string('authority_reference', 180)->nullable();
            $table->unsignedBigInteger('authority_evidence_id')->nullable()->index();
            $table->foreignId('authority_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('authority_verified_at')->nullable();
            $table->text('authority_verification_reason')->nullable();
            $table->string('voting_rule_version', 80)->nullable();
            $table->dateTime('nominations_open_at')->nullable();
            $table->dateTime('nominations_close_at')->nullable();
            $table->date('record_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code'], 'gov_elections_tenant_code_uq');
            $table->index(['tenant_id', 'status'], 'gov_elections_tenant_status_idx');
        });

        // Appointment records deliberately have no fixed seat count, role
        // catalogue, or quorum. Active decision authority is separately
        // checked by the election committee security service.
        Schema::create('governance_election_committee_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('member_role', 32);
            $table->boolean('ex_officio')->default(false);
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('appointed_at');
            $table->dateTime('recused_at')->nullable();
            $table->string('recusal_scope', 80)->nullable();
            $table->string('appointment_reference', 160);
            // Evidence is validated against tenant/election/status in the write service.
            // It is indexed here because the evidence table is created later in this migration.
            $table->unsignedBigInteger('appointment_evidence_id')->nullable()->index();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'election_id', 'user_id', 'status'], 'gov_elec_committee_member_user_idx');
            $table->index(['tenant_id', 'election_id', 'person_id'], 'gov_elec_committee_member_person_idx');
        });

        Schema::create('governance_election_authority_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->string('instrument_type', 48);
            $table->string('instrument_reference', 180);
            $table->date('instrument_date')->nullable();
            $table->string('storage_reference', 255);
            $table->char('content_sha256', 64);
            $table->string('status', 24)->default('pending_review')->index();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'election_id', 'status'], 'gov_elec_auth_evidence_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_authority_evidence');
        Schema::dropIfExists('governance_election_committee_members');
        Schema::dropIfExists('governance_elections');
    }
};
