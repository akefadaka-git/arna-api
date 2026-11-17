<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Multiple shareholders may nominate the same person; retain each
        // nomination submission independently from the committee candidate record.
        Schema::create('governance_election_nomination_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('election_id')->index();
            $table->unsignedBigInteger('candidate_person_id')->index();
            $table->unsignedBigInteger('nominated_by_shareholder_id')->index();
            $table->boolean('self_nomination')->default(false);
            $table->text('statement')->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->unsignedBigInteger('committee_candidate_id')->nullable()->index();
            $table->dateTime('submitted_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'candidate_person_id', 'nominated_by_shareholder_id'], 'gov_elec_nom_submission_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_nomination_submissions');
    }
};
