<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce tenant-consistent references across Governance meetings and elections.
 *
 * This is additive so environments that have already applied the original
 * Governance body and membership migrations receive the same constraints as
 * fresh installations. Global user and tenant references remain single-key
 * references; domain-owned rows must additionally match tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Composite referenced keys required by MySQL/InnoDB for tenant-aware FKs.
        Schema::table('governance_bodies', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'gb_tenant_id_id_uq');
        });
        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'gm_tenant_id_id_uq');
        });
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'gmeet_tenant_id_id_uq');
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'ge_tenant_id_id_uq');
        });
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'gen_tenant_id_id_uq');
            $table->unique(['tenant_id', 'election_id', 'id'], 'gen_tenant_election_id_uq');
        });

        // Replace single-column domain references with tenant-aware references.
        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->dropForeign('governance_memberships_governance_body_id_foreign');
        });
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->dropForeign('governance_meetings_governance_body_id_foreign');
        });
        Schema::table('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->dropForeign('governance_meeting_agenda_items_meeting_id_foreign');
        });
        Schema::table('governance_meeting_attendance', function (Blueprint $table): void {
            $table->dropForeign('governance_meeting_attendance_meeting_id_foreign');
            $table->dropForeign('governance_meeting_attendance_governance_membership_id_foreign');
        });
        Schema::table('governance_meeting_audit_events', function (Blueprint $table): void {
            $table->dropForeign('governance_meeting_audit_events_meeting_id_foreign');
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->dropForeign('governance_elections_governance_body_id_foreign');
        });
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->dropForeign('governance_election_nominations_election_id_foreign');
            $table->dropForeign('governance_election_nominations_governance_membership_id_foreign');
        });
        Schema::table('governance_election_eligible_voters', function (Blueprint $table): void {
            $table->dropForeign('governance_election_eligible_voters_election_id_foreign');
            $table->dropForeign('governance_election_eligible_voters_governance_membership_id_foreign');
        });
        Schema::table('governance_election_audit_events', function (Blueprint $table): void {
            $table->dropForeign('governance_election_audit_events_election_id_foreign');
        });
        Schema::table('governance_election_ballots', function (Blueprint $table): void {
            $table->dropForeign('governance_election_ballots_election_id_foreign');
            $table->dropForeign('governance_election_ballots_nomination_id_foreign');
        });

        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'governance_body_id'], 'gm_body_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_bodies')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'governance_body_id'], 'gmeet_body_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_bodies')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'meeting_id'], 'gmai_meeting_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_meetings')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_meeting_attendance', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'meeting_id'], 'gma_meeting_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_meetings')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['tenant_id', 'governance_membership_id'], 'gma_membership_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_memberships')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_meeting_audit_events', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'meeting_id'], 'gmae_meeting_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_meetings')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'governance_body_id'], 'ge_body_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_bodies')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'election_id'], 'gen_election_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['tenant_id', 'governance_membership_id'], 'gen_member_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_memberships')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_election_eligible_voters', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'election_id'], 'gev_election_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['tenant_id', 'governance_membership_id'], 'gev_member_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_memberships')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_election_audit_events', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'election_id'], 'gea_election_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
        Schema::table('governance_election_ballots', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'election_id'], 'geb_election_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('governance_elections')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['tenant_id', 'election_id', 'nomination_id'], 'geb_nomination_same_election_fk')
                ->references(['tenant_id', 'election_id', 'id'])->on('governance_election_nominations')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('governance_election_ballots', function (Blueprint $table): void {
            $table->dropForeign('geb_nomination_same_election_fk');
            $table->dropForeign('geb_election_same_tenant_fk');
        });
        Schema::table('governance_election_audit_events', function (Blueprint $table): void {
            $table->dropForeign('gea_election_same_tenant_fk');
        });
        Schema::table('governance_election_eligible_voters', function (Blueprint $table): void {
            $table->dropForeign('gev_member_same_tenant_fk');
            $table->dropForeign('gev_election_same_tenant_fk');
        });
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->dropForeign('gen_member_same_tenant_fk');
            $table->dropForeign('gen_election_same_tenant_fk');
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->dropForeign('ge_body_same_tenant_fk');
        });
        Schema::table('governance_meeting_audit_events', function (Blueprint $table): void {
            $table->dropForeign('gmae_meeting_same_tenant_fk');
        });
        Schema::table('governance_meeting_attendance', function (Blueprint $table): void {
            $table->dropForeign('gma_membership_same_tenant_fk');
            $table->dropForeign('gma_meeting_same_tenant_fk');
        });
        Schema::table('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->dropForeign('gmai_meeting_same_tenant_fk');
        });
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->dropForeign('gmeet_body_same_tenant_fk');
        });
        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->dropForeign('gm_body_same_tenant_fk');
        });

        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->foreign('governance_body_id')->references('id')->on('governance_bodies')->cascadeOnDelete();
        });
        Schema::table('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->foreign('meeting_id')->references('id')->on('governance_meetings')->restrictOnDelete();
        });
        Schema::table('governance_meeting_attendance', function (Blueprint $table): void {
            $table->foreign('meeting_id')->references('id')->on('governance_meetings')->restrictOnDelete();
            $table->foreign('governance_membership_id')->references('id')->on('governance_memberships')->restrictOnDelete();
        });
        Schema::table('governance_meeting_audit_events', function (Blueprint $table): void {
            $table->foreign('meeting_id')->references('id')->on('governance_meetings')->restrictOnDelete();
        });
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->foreign('governance_body_id')->references('id')->on('governance_bodies')->restrictOnDelete();
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->foreign('governance_body_id')->references('id')->on('governance_bodies')->restrictOnDelete();
        });
        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->foreign('election_id')->references('id')->on('governance_elections')->restrictOnDelete();
            $table->foreign('governance_membership_id')->references('id')->on('governance_memberships')->restrictOnDelete();
        });
        Schema::table('governance_election_eligible_voters', function (Blueprint $table): void {
            $table->foreign('election_id')->references('id')->on('governance_elections')->restrictOnDelete();
            $table->foreign('governance_membership_id')->references('id')->on('governance_memberships')->restrictOnDelete();
        });
        Schema::table('governance_election_audit_events', function (Blueprint $table): void {
            $table->foreign('election_id')->references('id')->on('governance_elections')->restrictOnDelete();
        });
        Schema::table('governance_election_ballots', function (Blueprint $table): void {
            $table->foreign('election_id')->references('id')->on('governance_elections')->restrictOnDelete();
            $table->foreign('nomination_id')->references('id')->on('governance_election_nominations')->restrictOnDelete();
        });

        Schema::table('governance_election_nominations', function (Blueprint $table): void {
            $table->dropUnique('gen_tenant_election_id_uq');
            $table->dropUnique('gen_tenant_id_id_uq');
        });
        Schema::table('governance_elections', function (Blueprint $table): void {
            $table->dropUnique('ge_tenant_id_id_uq');
        });
        Schema::table('governance_meetings', function (Blueprint $table): void {
            $table->dropUnique('gmeet_tenant_id_id_uq');
        });
        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->dropUnique('gm_tenant_id_id_uq');
        });
        Schema::table('governance_bodies', function (Blueprint $table): void {
            $table->dropUnique('gb_tenant_id_id_uq');
        });
    }
};
