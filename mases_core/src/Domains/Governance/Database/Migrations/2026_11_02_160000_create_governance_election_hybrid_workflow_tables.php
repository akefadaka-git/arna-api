<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid Board Election workflow: candidate intake, shareholder consultation,
 * committee decisions and anonymous mixed-channel ballot handling.
 *
 * Consultation records are explicitly non-binding. Binding ballots are
 * anonymous and are not joined to shareholder or voter identifiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->string('voting_mode', 24)->default('physical')->after('voting_method');
            $table->boolean('electronic_voting_authorized')->default(false);
            $table->string('electronic_voting_authority_reference', 160)->nullable();
            $table->dateTime('candidate_list_published_at')->nullable();
            $table->unsignedBigInteger('candidate_list_published_by')->nullable();
            $table->dateTime('consultation_closes_at')->nullable();
        });

        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->unsignedBigInteger('candidate_person_id')->nullable()->index('gov_elec_nom_candidate_person_idx');
            $table->string('candidate_name_snapshot', 180)->nullable();
            $table->string('nomination_source', 24)->default('shareholder');
            $table->unsignedBigInteger('nominated_by_shareholder_id')->nullable()->index('gov_elec_nom_nominated_by_idx');
            $table->text('nomination_statement')->nullable();
            $table->string('committee_status', 24)->default('pending')->index();
            $table->text('committee_reason')->nullable();
            $table->dateTime('committee_decided_at')->nullable();
            $table->unsignedBigInteger('committee_decided_by')->nullable();
            $table->boolean('final_ballot_approved')->default(false);
            $table->dateTime('final_ballot_approved_at')->nullable();
        });

        Schema::create('governance_election_committee_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->string('member_role', 32);
            $table->boolean('ex_officio')->default(false);
            $table->string('status', 20)->default('active');
            $table->dateTime('appointed_at');
            $table->dateTime('recused_at')->nullable();
            $table->string('recusal_scope', 80)->nullable();
            $table->string('appointment_reference', 160);
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'person_id'], 'gov_elec_committee_person_uq');
        });

        Schema::create('governance_election_committee_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('nomination_id')->nullable()->index();
            $table->string('decision_type', 32);
            $table->string('decision', 24);
            $table->text('reason');
            $table->unsignedBigInteger('decided_by');
            $table->string('meeting_reference', 160)->nullable();
            $table->string('evidence_reference', 160)->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();
        });

        Schema::create('governance_election_candidate_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('candidate_nomination_id')->index();
            $table->unsignedBigInteger('shareholder_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'candidate_nomination_id', 'shareholder_id'], 'gov_elec_feedback_once');
        });

        Schema::create('governance_election_candidate_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('shareholder_id')->index();
            $table->unsignedBigInteger('candidate_nomination_id')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'shareholder_id', 'candidate_nomination_id'], 'gov_elec_preference_once');
        });

        Schema::create('governance_election_voting_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('voter_entitlement_id')->unique();
            $table->string('channel', 16);
            $table->char('credential_hash', 64)->unique();
            $table->dateTime('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'voter_entitlement_id'], 'gov_elec_credential_entitlement_uq');
        });

        Schema::create('governance_election_secret_ballots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->uuid('ballot_public_id')->unique();
            $table->string('channel', 16);
            $table->json('encrypted_selections');
            $table->string('encryption_key_version', 40);
            $table->string('capture_reference', 160)->nullable();
            $table->dateTime('cast_at');
            $table->timestamps();
            $table->index(['tenant_id', 'election_id', 'channel'], 'gov_elec_secret_ballot_channel_idx');
        });

        Schema::create('governance_election_manual_count_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->string('batch_reference', 100);
            $table->string('polling_station', 120)->nullable();
            $table->unsignedInteger('ballots_received');
            $table->unsignedInteger('ballots_valid');
            $table->unsignedInteger('ballots_rejected');
            $table->string('sealed_box_reference', 160);
            $table->string('count_sheet_reference', 160);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('witnessed_by')->nullable();
            $table->dateTime('counted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'batch_reference'], 'gov_elec_manual_batch_uq');
        });

        Schema::create('governance_election_manual_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedBigInteger('candidate_nomination_id')->nullable()->index();
            $table->string('line_type', 24)->default('candidate');
            $table->unsignedInteger('ballot_count');
            $table->decimal('share_vote_weight', 20, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_manual_count_lines');
        Schema::dropIfExists('governance_election_manual_count_batches');
        Schema::dropIfExists('governance_election_secret_ballots');
        Schema::dropIfExists('governance_election_voting_credentials');
        Schema::dropIfExists('governance_election_candidate_preferences');
        Schema::dropIfExists('governance_election_candidate_feedback');
        Schema::dropIfExists('governance_election_committee_decisions');
        Schema::dropIfExists('governance_election_committee_members');
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->dropColumn([
                'candidate_person_id', 'candidate_name_snapshot', 'nomination_source',
                'nominated_by_shareholder_id', 'nomination_statement', 'committee_status',
                'committee_reason', 'committee_decided_at', 'committee_decided_by',
                'final_ballot_approved', 'final_ballot_approved_at',
            ]);
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->dropColumn([
                'voting_mode', 'electronic_voting_authorized',
                'electronic_voting_authority_reference', 'candidate_list_published_at',
                'candidate_list_published_by', 'consultation_closes_at',
            ]);
        });
    }
};
