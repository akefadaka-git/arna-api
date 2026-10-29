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
            $table->foreignId('created_by')->nullable()->after('governance_body_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('frozen_eligible_voter_count')->nullable()->after('rules_snapshot');
            $table->unsignedSmallInteger('current_round')->default(1)->after('frozen_eligible_voter_count');
            $table->string('challenge_bylaw_reference', 255)->nullable()->after('current_round');
            $table->timestamp('challenge_window_ends_at')->nullable()->after('challenge_bylaw_reference');
            $table->unsignedBigInteger('winner_nomination_id')->nullable()->after('challenge_window_ends_at');
            $table->json('result_summary_json')->nullable()->after('winner_nomination_id');
        });

        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->string('nomination_source', 24)->default('third_party')->after('governance_membership_id');
            $table->foreignId('nominated_by_user_id')->nullable()->after('nomination_source')->constrained('users')->restrictOnDelete();
            $table->timestamp('candidate_consent_at')->nullable()->after('nominated_by_user_id');
        });

        Schema::table('governance_election_ballots', function (Blueprint $table): void {
            $table->unsignedSmallInteger('round_number')->default(1)->after('nomination_id');
            $table->index(['tenant_id', 'election_id', 'round_number', 'nomination_id'], 'gov_election_ballot_round_count_idx');
        });

        Schema::create('governance_election_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->string('round_type', 16);
            $table->string('status', 24)->default('planned');
            $table->unsignedInteger('eligible_voter_count');
            $table->unsignedInteger('ballots_cast_count')->default(0);
            $table->unsignedInteger('valid_ballot_count')->default(0);
            $table->unsignedBigInteger('winner_nomination_id')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'round_number'], 'gov_election_round_tenant_no_uq');
            $table->unique(['tenant_id', 'election_id', 'round_number', 'id'], 'gov_election_round_tenant_no_id_uq');
        });

        Schema::create('governance_election_round_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->unsignedBigInteger('nomination_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'round_number', 'nomination_id'], 'gov_election_round_candidate_uq');
        });

        Schema::create('governance_election_round_voters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->unsignedSmallInteger('round_number');
            $table->foreignId('governance_membership_id')->constrained('governance_memberships')->restrictOnDelete();
            $table->timestamp('cast_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'round_number', 'governance_membership_id'], 'gov_election_round_voter_uq');
            $table->index(['tenant_id', 'election_id', 'round_number', 'cast_at'], 'gov_election_round_voter_cast_idx');
        });

        Schema::create('governance_election_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('governance_elections')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('challenge_type', 24)->default('challenge');
            $table->text('reason');
            $table->json('evidence_references')->nullable();
            $table->string('status', 24)->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->index(['tenant_id', 'election_id', 'status'], 'gov_election_challenge_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_challenges');
        Schema::dropIfExists('governance_election_round_voters');
        Schema::dropIfExists('governance_election_round_candidates');
        Schema::dropIfExists('governance_election_rounds');
        Schema::table('governance_election_ballots', function (Blueprint $table): void {
            $table->dropIndex('gov_election_ballot_round_count_idx');
            $table->dropColumn('round_number');
        });
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('nominated_by_user_id');
            $table->dropColumn(['nomination_source', 'candidate_consent_at']);
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'frozen_eligible_voter_count',
                'current_round',
                'challenge_bylaw_reference',
                'challenge_window_ends_at',
                'winner_nomination_id',
                'result_summary_json',
            ]);
        });
    }
};
