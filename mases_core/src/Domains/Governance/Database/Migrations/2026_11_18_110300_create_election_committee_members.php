<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_election_committee_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('user_id');
            $table->string('member_role', 32);
            $table->boolean('ex_officio')->default(false);
            $table->string('status', 16)->default('active');
            $table->dateTime('appointed_at');
            $table->unsignedBigInteger('appointed_by');
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('recused_at')->nullable();
            $table->string('recusal_scope', 80)->nullable();
            $table->string('appointment_reference', 160);
            $table->timestamps();
            $table->unique(['tenant_id', 'election_id', 'user_id'], 'gov_elec_committee_member_uq');
            $table->foreign(['tenant_id', 'election_id'], 'gov_elec_committee_member_election_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')->restrictOnDelete();
            $table->foreign('person_id', 'gov_elec_committee_member_person_fk')
                ->references('id')->on('people')->restrictOnDelete();
            $table->foreign('user_id', 'gov_elec_committee_member_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('appointed_by', 'gov_elec_committee_member_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'election_id', 'status', 'member_role'], 'gov_elec_committee_member_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_election_committee_members');
    }
};
