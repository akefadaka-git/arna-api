<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'gov_elec_cand_tenant_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections', 'id', 'gov_elec_cand_election_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('candidate_person_id')->constrained('people', 'id', 'gov_elec_cand_person_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('candidate_type', 24);
            $table->string('intake_status', 24)->default('submitted');
            $table->string('eligibility_status', 32)->default('unassessed');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'candidate_person_id'], 'gov_elec_candidate_once');
            $table->index(['tenant_id', 'election_id', 'intake_status'], 'gov_elec_cand_intake_idx');
            $table->index(['tenant_id', 'election_id', 'eligibility_status'], 'gov_elec_cand_elig_idx');
        });

        Schema::create('governance_election_nominations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'gov_elec_nom_tenant_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections', 'id', 'gov_elec_nom_election_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('governance_election_candidates', 'id', 'gov_elec_nom_candidate_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('nominator_shareholder_id')->constrained('shareholders', 'id', 'gov_elec_nom_holder_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('nominator_user_id')->constrained('users', 'id', 'gov_elec_nom_user_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('nominator_person_id')->constrained('people', 'id', 'gov_elec_nom_person_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('self_nomination')->default(false);
            $table->text('statement')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('idempotency_key', 128);
            $table->unsignedBigInteger('supersedes_nomination_id')->nullable();
            $table->dateTime('submitted_at');
            $table->timestamps();
            $table->foreign('supersedes_nomination_id', 'gov_elec_nom_supersedes_fk')
                ->references('id')->on('governance_election_nominations')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['tenant_id', 'election_id', 'idempotency_key'], 'gov_elec_nom_idempotency_uq');
            $table->unique(['tenant_id', 'election_id', 'candidate_id', 'nominator_shareholder_id', 'revision'], 'gov_elec_nom_revision_uq');
            $table->index(['tenant_id', 'election_id', 'candidate_id', 'status'], 'gov_elec_nom_candidate_idx');
        });

        Schema::create('governance_election_candidate_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'gov_elec_ce_tenant_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections', 'id', 'gov_elec_ce_election_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('governance_election_candidates', 'id', 'gov_elec_ce_candidate_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files', 'id', 'gov_elec_ce_file_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('evidence_category', 64);
            $table->char('content_sha256', 64);
            $table->foreignId('submitted_by')->constrained('users', 'id', 'gov_elec_ce_submitter_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'gov_elec_ce_reviewer_fk')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'candidate_id', 'file_id'], 'gov_elec_ce_file_once');
            $table->index(['tenant_id', 'election_id', 'candidate_id', 'status'], 'gov_elec_ce_candidate_idx');
        });

        Schema::create('governance_election_candidate_eligibility_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants', 'id', 'gov_elec_ced_tenant_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections', 'id', 'gov_elec_ced_election_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('governance_election_candidates', 'id', 'gov_elec_ced_candidate_fk')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('decision', 32);
            $table->text('reason');
            $table->char('evidence_snapshot_sha256', 64);
            $table->foreignId('decided_by')->constrained('users', 'id', 'gov_elec_ced_decider_fk')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'election_id', 'candidate_id', 'created_at'], 'gov_elec_ced_candidate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_candidate_eligibility_decisions');
        Schema::dropIfExists('governance_election_candidate_evidence');
        Schema::dropIfExists('governance_election_nominations');
        Schema::dropIfExists('governance_election_candidates');
    }
};
