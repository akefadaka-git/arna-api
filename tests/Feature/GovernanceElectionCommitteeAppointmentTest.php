<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeAppointmentService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceElectionCommitteeAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewed_evidence_is_required_and_appointment_is_audited_and_revocable(): void
    {
        $tenant = $this->tenant();
        $recorder = $this->user($tenant, 'recorder@example.test');
        $reviewer = $this->user($tenant, 'reviewer@example.test');
        $verifier = $this->user($tenant, 'verifier@example.test');
        $appointer = $this->user($tenant, 'appointer@example.test');
        $member = $this->user($tenant, 'member@example.test');
        $person = $this->person($tenant, $member);
        foreach (['appoint', 'revoke', 'view'] as $permission) {
            $this->grant($appointer, 'governance.election.committee.' . $permission);
        }

        $authority = app(GovernanceElectionAuthorityService::class);
        $election = $authority->createPlanned((int) $tenant->id, 'COMMITTEE-' . $tenant->id, 'Committee Test', null, null, $recorder);
        $evidence = $authority->recordAuthorityEvidence(
            (int) $tenant->id, (int) $election->id, 'general_assembly_resolution',
            'GA-COMMITTEE-' . $tenant->id, null, 'private/election/ga.pdf', str_repeat('a', 64), $recorder,
        );
        $service = app(GovernanceElectionCommitteeAppointmentService::class);

        try {
            $service->appoint((int) $tenant->id, (int) $election->id, (int) $person->id, (int) $member->id,
                (int) $evidence->id, 'GA-COMMITTEE', 'committee_member', false, now()->toDateTimeString(), $appointer);
            $this->fail('Unreviewed evidence must not authorize an appointment.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseMissing('governance_election_committee_members', ['election_id' => $election->id]);
        }

        $authority->reviewAuthorityEvidence((int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'reviewed', 'The instrument and stored evidence reference were reviewed.', $reviewer);
        $authority->verifyAuthority((int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'Authorized verifier attested to the governing instrument.', $verifier);

        $appointed = $service->appoint((int) $tenant->id, (int) $election->id, (int) $person->id, (int) $member->id,
            (int) $evidence->id, 'GA-COMMITTEE', 'committee_member', false, now()->toDateTimeString(), $appointer);
        $this->assertSame('active', $appointed->status);
        $this->assertSame((int) $evidence->id, (int) $appointed->appointment_evidence_id);

        $revoked = $service->revoke((int) $tenant->id, (int) $election->id, (int) $appointed->id,
            'Appointment concluded by the appointing authority.', $appointer);
        $this->assertSame('revoked', $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('Appointment concluded by the appointing authority.', $revoked->revocation_reason);

        $this->assertDatabaseHas('governance_election_committee_security_events', [
            'tenant_id' => $tenant->id, 'election_id' => $election->id, 'event_type' => 'committee_member.appointed',
        ]);
        $this->assertDatabaseHas('governance_election_committee_security_events', [
            'tenant_id' => $tenant->id, 'election_id' => $election->id, 'event_type' => 'committee_member.revoked',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id, 'resource_type' => 'governance_election_committee_member',
            'resource_id' => (string) $appointed->id, 'action' => 'governance.election.committee.member.appointed',
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name_am' => 'የኮሚቴ ሙከራ', 'name_en' => 'Committee Test',
            'slug' => 'committee-' . bin2hex(random_bytes(4)),
            'domain' => 'committee-' . bin2hex(random_bytes(4)) . '.example.test', 'is_active' => true,
        ]);
    }

    private function user(Tenant $tenant, string $email): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Committee', 'father_name_en' => 'Test', 'email' => $email,
            'password' => bcrypt('not-used-in-test'), 'locale' => 'am', 'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now()]);
        return $user;
    }

    private function person(Tenant $tenant, User $user): Person
    {
        $person = new Person(['first_name_en' => 'Committee', 'father_name_en' => 'Member']);
        $person->user_id = $user->id;
        $person->status = 'active';
        $person->save();
        PersonTenantAccess::query()->create([
            'person_id' => $person->id, 'tenant_id' => $tenant->id, 'created_by' => $user->id, 'status' => 'active',
        ]);
        return $person;
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(['slug' => $slug], [
            'name_am' => $slug, 'name_en' => $slug, 'description_am' => $slug, 'description_en' => $slug,
            'category' => 'governance', 'scope' => 'tenant', 'tenant_id' => null,
            'application_id' => null, 'is_active' => true, 'is_system' => true,
        ]);
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
    }
}
