<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_nomination_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('nomination_submission_id');
            $table->unsignedInteger('sequence');
            $table->string('event_type', 24);
            $table->unsignedBigInteger('actor_user_id');
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'nomination_submission_id', 'sequence'], 'gov_nom_event_seq_uq');
            $table->foreign(['tenant_id', 'election_id', 'candidate_id'], 'gov_nom_event_candidate_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_candidates')->restrictOnDelete();
            $table->foreign('nomination_submission_id', 'gov_nom_event_submission_fk')
                ->references('id')->on('governance_election_nomination_submissions')->restrictOnDelete();
            $table->foreign('actor_user_id', 'gov_nom_event_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'candidate_id', 'event_type'], 'gov_nom_event_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_nomination_events');
    }
};
