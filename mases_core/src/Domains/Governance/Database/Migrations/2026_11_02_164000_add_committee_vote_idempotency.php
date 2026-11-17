<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_election_committee_decisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('submission_id')->nullable()->after('nomination_id')->index('gov_elec_decision_submission_idx');
            $table->unique(
                ['tenant_id', 'election_id', 'decision_type', 'submission_id', 'decided_by'],
                'gov_elec_submission_vote_once'
            );
            $table->unique(
                ['tenant_id', 'election_id', 'decision_type', 'nomination_id', 'decided_by'],
                'gov_elec_candidate_vote_once'
            );
        });
    }

    public function down(): void
    {
        Schema::table('governance_election_committee_decisions', function (Blueprint $table): void {
            $table->dropUnique('gov_elec_candidate_vote_once');
            $table->dropUnique('gov_elec_submission_vote_once');
            $table->dropIndex('gov_elec_decision_submission_idx');
            $table->dropColumn('submission_id');
        });
    }
};
