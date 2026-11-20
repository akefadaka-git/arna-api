<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'gov_elections_tenant_id_uq');
        });

        Schema::create('governance_election_authority_evidence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->string('instrument_type', 48);
            $table->string('instrument_reference', 180);
            $table->date('instrument_date')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->string('storage_reference', 80);
            $table->char('content_sha256', 64);
            $table->string('status', 24)->default('pending_review')->index();
            $table->unsignedBigInteger('recorded_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'election_id', 'status'], 'gov_elec_auth_evidence_scope_idx');
            $table->index(['tenant_id', 'election_id', 'document_id'], 'gov_elec_auth_evidence_doc_idx');
            $table->foreign('tenant_id', 'gov_elec_auth_evidence_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_auth_evidence_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign('recorded_by', 'gov_elec_auth_evidence_recorder_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by', 'gov_elec_auth_evidence_reviewer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('verified_by', 'gov_elec_auth_evidence_verifier_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->unsignedBigInteger('authority_evidence_id')->nullable()->index();
            $table->unsignedBigInteger('authority_verified_by')->nullable()->index();
            $table->dateTime('authority_verified_at')->nullable();
            $table->text('authority_verification_reason')->nullable();
            $table->foreign('authority_evidence_id', 'gov_elections_authority_evidence_fk')
                ->references('id')->on('governance_election_authority_evidence')->restrictOnDelete();
            $table->foreign('authority_verified_by', 'gov_elections_authority_verifier_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('governance_election_committee_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('user_id');
            $table->string('member_role', 32);
            $table->boolean('ex_officio')->default(false);
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('appointed_at');
            $table->dateTime('recused_at')->nullable();
            $table->string('recusal_scope', 80)->nullable();
            $table->string('appointment_reference', 160);
            $table->unsignedBigInteger('appointment_evidence_id');
            $table->unsignedBigInteger('appointed_by');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'election_id', 'user_id', 'status'], 'gov_elec_member_user_status_idx');
            $table->index(['tenant_id', 'election_id', 'person_id'], 'gov_elec_member_person_idx');
            $table->foreign('tenant_id', 'gov_elec_member_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_member_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign('person_id', 'gov_elec_member_person_fk')
                ->references('id')->on('people')->restrictOnDelete();
            $table->foreign('user_id', 'gov_elec_member_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('appointment_evidence_id', 'gov_elec_member_evidence_fk')
                ->references('id')->on('governance_election_authority_evidence')->restrictOnDelete();
            $table->foreign('appointed_by', 'gov_elec_member_appointer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by', 'gov_elec_member_revoker_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Election authority and committee appointment schema is forward-only; recover through a verified database backup.');
    }
};
