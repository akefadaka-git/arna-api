<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCandidateIntakeService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeAppointmentService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RuntimeException;
use Tests\TestCase;

final class GovernanceElectionCandidateIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_shareholder_can_nominate_active_non_shareholder_person_and_amendment_preserves_history(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'candidate-admin@example.test');
        $reviewer = $this->user($tenant, 'candidate-reviewer@example.test');
        $verifier = $this->user($tenant, 'candidate-verifier@example.test');
        $nominator = $this->user($tenant, 'nominator@example.test');
        $nominatorPerson = $this->person($tenant, $nominator);
        $candidatePerson = $this->person($tenant, null);
        $shareholderId = $this->shareholder($tenant, $nominator, $nominatorPerson);
        $this->grant($nominator, 'governance.election.nomination.submit');

        [$election] = $this->verifiedElection($tenant, $admin, $reviewer, $verifier, 'NOMINATION-' . $tenant->id);

        $service = app(GovernanceElectionCandidateIntakeService::class);
        $first = $service->submitNomination(
            (int) $tenant->id, (int) $election->id, (int) $candidatePerson->id,
            $shareholderId, 'candidate-nom-001', 'I nominate this active Person.', $nominator,
        );
        $retry = $service->submitNomination(
            (int) $tenant->id, (int) $election->id, (int) $candidatePerson->id,
            $shareholderId, 'candidate-nom-001', 'I nominate this active Person.', $nominator,
        );

        $this->assertSame($first['nomination']->id, $retry['nomination']->id);
        $this->assertSame('non_shareholder', $first['candidate']->candidate_type);
        $this->assertSame('unassessed', $first['candidate']->eligibility_status);
        $this->assertFalse($first['nomination']->self_nomination);

        $amended = $service->amendNomination(
            (int) $tenant->id, (int) $election->id, (int) $first['nomination']->id,
            'candidate-nom-002', 'Corrected nomination statement.', $nominator,
        );

        $this->assertSame(2, (int) $amended->revision);
        $this->assertSame((int) $first['nomination']->id, (int) $amended->supersedes_nomination_id);
        $this->assertDatabaseHas('governance_election_nominations', [
            'id' => $first['nomination']->id, 'status' => 'superseded',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'resource_type' => 'governance_election_nomination',
            'resource_id' => (string) $amended->id,
            'action' => 'governance.election.nomination.amended',
        ]);
    }

    public function test_self_nomination_is_derived_from_canonical_shareholder_person_identity(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'self-admin@example.test');
        $reviewer = $this->user($tenant, 'self-reviewer@example.test');
        $verifier = $this->user($tenant, 'self-verifier@example.test');
        $nominator = $this->user($tenant, 'self-nominator@example.test');
        $person = $this->person($tenant, $nominator);
        $shareholderId = $this->shareholder($tenant, $nominator, $person);
        $this->grant($nominator, 'governance.election.nomination.submit');
        [$election] = $this->verifiedElection($tenant, $admin, $reviewer, $verifier, 'SELF-' . $tenant->id);

        $result = app(GovernanceElectionCandidateIntakeService::class)->submitNomination(
            (int) $tenant->id, (int) $election->id, (int) $person->id,
            $shareholderId, 'self-nomination-001', null, $nominator,
        );

        $this->assertTrue($result['nomination']->self_nomination);
        $this->assertSame('shareholder', $result['candidate']->candidate_type);
    }

    public function test_suspended_tenant_member_cannot_submit_a_nomination(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'suspended-admin@example.test');
        $reviewer = $this->user($tenant, 'suspended-reviewer@example.test');
        $verifier = $this->user($tenant, 'suspended-verifier@example.test');
        $nominator = $this->user($tenant, 'suspended-nominator@example.test');
        $nominatorPerson = $this->person($tenant, $nominator);
        $candidatePerson = $this->person($tenant, null);
        $shareholderId = $this->shareholder($tenant, $nominator, $nominatorPerson);
        $this->grant($nominator, 'governance.election.nomination.submit');
        [$election] = $this->verifiedElection($tenant, $admin, $reviewer, $verifier, 'SUSPENDED-' . $tenant->id);
        $nominator->memberships()->update(['status' => 'suspended']);

        try {
            app(GovernanceElectionCandidateIntakeService::class)->submitNomination(
                (int) $tenant->id, (int) $election->id, (int) $candidatePerson->id,
                $shareholderId, 'suspended-nomination-001', null, $nominator,
            );
            $this->fail('A suspended tenant member must not submit a nomination.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('governance_election_candidates', ['election_id' => $election->id]);
        }
    }

    public function test_nomination_window_and_active_shareholder_are_enforced(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'window-admin@example.test');
        $reviewer = $this->user($tenant, 'window-reviewer@example.test');
        $verifier = $this->user($tenant, 'window-verifier@example.test');
        $nominator = $this->user($tenant, 'window-nominator@example.test');
        $nominatorPerson = $this->person($tenant, $nominator);
        $candidatePerson = $this->person($tenant, null);
        $shareholderId = $this->shareholder($tenant, $nominator, $nominatorPerson);
        $this->grant($nominator, 'governance.election.nomination.submit');
        $service = app(GovernanceElectionCandidateIntakeService::class);

        [$futureElection] = $this->verifiedElection(
            $tenant, $admin, $reviewer, $verifier, 'FUTURE-' . $tenant->id,
            now()->addHour()->toDateTimeString(), now()->addHours(2)->toDateTimeString(),
        );
        try {
            $service->submitNomination((int) $tenant->id, (int) $futureElection->id, (int) $candidatePerson->id,
                $shareholderId, 'future-window-001', null, $nominator);
            $this->fail('A future nomination window must reject early submissions.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('governance_election_candidates', ['election_id' => $futureElection->id]);
        }

        [$openElection] = $this->verifiedElection($tenant, $admin, $reviewer, $verifier, 'OPEN-' . $tenant->id);
        DB::table('shareholders')->where('id', $shareholderId)->update(['is_active' => false]);
        try {
            $service->submitNomination((int) $tenant->id, (int) $openElection->id, (int) $candidatePerson->id,
                $shareholderId, 'inactive-holder-001', null, $nominator);
            $this->fail('An inactive shareholder must not nominate.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('governance_election_candidates', ['election_id' => $openElection->id]);
        }
    }

    public function test_candidate_evidence_is_private_hashed_and_reviewed_by_a_different_actor(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'evidence-admin@example.test');
        $authorityReviewer = $this->user($tenant, 'evidence-authority-reviewer@example.test');
        $verifier = $this->user($tenant, 'evidence-verifier@example.test');
        $nominator = $this->user($tenant, 'evidence-nominator@example.test');
        $nominatorPerson = $this->person($tenant, $nominator);
        $candidatePerson = $this->person($tenant, null);
        $shareholderId = $this->shareholder($tenant, $nominator, $nominatorPerson);
        $this->grant($nominator, 'governance.election.nomination.submit');
        $this->grant($nominator, 'governance.election.candidate.evidence.submit');
        $this->grant($nominator, 'file.view');

        $evidenceReviewer = $this->user($tenant, 'evidence-reviewer@example.test');
        $this->grant($evidenceReviewer, 'governance.election.candidate.evidence.review');
        [$election] = $this->verifiedElection($tenant, $admin, $authorityReviewer, $verifier, 'EVIDENCE-' . $tenant->id);

        $service = app(GovernanceElectionCandidateIntakeService::class);
        $submission = $service->submitNomination((int) $tenant->id, (int) $election->id,
            (int) $candidatePerson->id, $shareholderId, 'evidence-nom-001', null, $nominator);
        Storage::disk('local')->put('candidate/identity.pdf', 'synthetic candidate evidence bytes');
        $file = File::query()->create([
            'user_id' => $nominator->id, 'tenant_id' => $tenant->id,
            'original_name' => 'identity.pdf', 'name' => 'identity-test.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf',
            'size' => strlen('synthetic candidate evidence bytes'), 'path' => 'candidate/identity.pdf',
            'disk' => 'local', 'version' => 1, 'status' => 'published',
            'is_public' => false, 'metadata' => [],
        ]);

        auth()->login($nominator);
        $evidence = $service->attachEvidence((int) $tenant->id, (int) $election->id,
            (int) $submission['candidate']->id, (int) $file->id, 'identity_reference', $nominator);
        $this->assertSame(hash('sha256', 'synthetic candidate evidence bytes'), $evidence->content_sha256);
        $this->assertSame('pending_review', $evidence->status);

        auth()->login($evidenceReviewer);
        $reviewed = $service->reviewEvidence((int) $tenant->id, (int) $election->id,
            (int) $submission['candidate']->id, (int) $evidence->id,
            'accepted', 'Evidence matches the submitted candidate record.', $evidenceReviewer);
        $this->assertSame('accepted', $reviewed->status);
        $this->assertSame((int) $evidenceReviewer->id, (int) $reviewed->reviewed_by);

        $secondFile = File::query()->create([
            'user_id' => $nominator->id, 'tenant_id' => $tenant->id,
            'original_name' => 'support.pdf', 'name' => 'support-test.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf',
            'size' => 8, 'path' => 'candidate/support.pdf',
            'disk' => 'local', 'version' => 1, 'status' => 'published',
            'is_public' => false, 'metadata' => [],
        ]);
        Storage::disk('local')->put('candidate/support.pdf', 'support!');
        config(['mases.features.audit_enabled' => false]);
        try {
            $service->attachEvidence((int) $tenant->id, (int) $election->id,
                (int) $submission['candidate']->id, (int) $secondFile->id, 'supporting_record', $nominator);
            $this->fail('A missing required audit record must roll back candidate evidence.');
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('governance_election_candidate_evidence', ['file_id' => $secondFile->id]);
        }
    }

    public function test_eligibility_remains_deferred_until_authoritative_criteria_and_requires_unconflicted_committee(): void
    {
        $tenant = $this->tenant();
        $admin = $this->user($tenant, 'screen-admin@example.test');
        $authorityReviewer = $this->user($tenant, 'screen-authority-reviewer@example.test');
        $verifier = $this->user($tenant, 'screen-verifier@example.test');
        $nominator = $this->user($tenant, 'screen-nominator@example.test');
        $nominatorPerson = $this->person($tenant, $nominator);
        $candidatePerson = $this->person($tenant, null);
        $shareholderId = $this->shareholder($tenant, $nominator, $nominatorPerson);
        $this->grant($nominator, 'governance.election.nomination.submit');
        [$election, $authorityEvidence] = $this->verifiedElection($tenant, $admin, $authorityReviewer, $verifier, 'SCREEN-' . $tenant->id);

        $committeeUser = $this->user($tenant, 'screen-committee@example.test');
        $committeePerson = $this->person($tenant, $committeeUser);
        $this->grant($admin, 'governance.election.committee.appoint');
        $this->grant($committeeUser, 'governance.election.committee.decide');
        $this->grant($committeeUser, 'governance.election.candidate.screen');

        $member = app(GovernanceElectionCommitteeAppointmentService::class)->appoint(
            (int) $tenant->id, (int) $election->id, (int) $committeePerson->id, (int) $committeeUser->id,
            (int) $authorityEvidence->id, 'GA-SCREEN-APPOINTMENT', 'committee_member', false,
            now()->toDateTimeString(), $admin,
        );
        $this->assertSame('active', $member->status);

        $candidate = app(GovernanceElectionCandidateIntakeService::class)->submitNomination(
            (int) $tenant->id, (int) $election->id, (int) $candidatePerson->id,
            $shareholderId, 'screen-nom-001', null, $nominator,
        )['candidate'];

        $decision = app(GovernanceElectionCandidateIntakeService::class)->deferEligibility(
            (int) $tenant->id, (int) $election->id, (int) $candidate->id,
            'Operative candidate qualification criteria have not yet been approved.', $committeeUser,
        );

        $this->assertSame('deferred_unresolved', $decision->decision);
        $this->assertDatabaseHas('governance_election_candidates', [
            'id' => $candidate->id, 'eligibility_status' => 'deferred_unresolved',
        ]);
        $this->assertDatabaseHas('governance_election_committee_security_events', [
            'tenant_id' => $tenant->id, 'election_id' => $election->id,
            'event_type' => 'candidate.screening.deferred_unresolved',
        ]);
    }

    private function tenant(): Tenant
    {
        $suffix = bin2hex(random_bytes(4));
        return Tenant::query()->create([
            'name_am' => 'የእጩ ሙከራ', 'name_en' => 'Candidate Test',
            'slug' => 'candidate-' . $suffix, 'domain' => 'candidate-' . $suffix . '.example.test',
            'is_active' => true,
        ]);
    }

    private function user(Tenant $tenant, string $email): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Candidate', 'father_name_en' => 'Test',
            'email' => $email, 'password' => bcrypt('not-used-in-test'),
            'locale' => 'am', 'is_active' => true, 'default_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now()]);
        return $user;
    }

    private function person(Tenant $tenant, ?User $user): Person
    {
        $person = new Person(['first_name_en' => 'Candidate', 'father_name_en' => 'Person']);
        $person->user_id = $user?->id;
        $person->status = 'active';
        $person->save();
        PersonTenantAccess::query()->create([
            'person_id' => $person->id, 'tenant_id' => $tenant->id,
            'created_by' => $user?->id, 'status' => 'active',
        ]);
        return $person;
    }

    private function shareholder(Tenant $tenant, User $user, Person $person): int
    {
        return (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_type' => 'individual',
            'first_name_en' => 'Nominator', 'user_id' => $user->id,
            'representative_user_id' => null, 'person_id' => $person->id,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection, 1: \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionAuthorityEvidence} */
    private function verifiedElection(
        Tenant $tenant, User $recorder, User $reviewer, User $verifier, string $code,
        ?string $opensAt = null, ?string $closesAt = null,
    ): array {
        $service = app(GovernanceElectionAuthorityService::class);
        $election = $service->createPlanned((int) $tenant->id, $code, 'Candidate Intake Test', null, null, $recorder);
        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->id, (int) $election->id, 'general_assembly_resolution',
            'GA-' . $code, null, 'private/election/' . $code . '.pdf', str_repeat('a', 64), $recorder,
        );
        $service->reviewAuthorityEvidence((int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'reviewed', 'The authority evidence was reviewed for this test.', $reviewer);
        $service->verifyAuthority((int) $tenant->id, (int) $election->id, (int) $evidence->id,
            'The authority was attested for this test.', $verifier);
        $service->openNominations(
            (int) $tenant->id, (int) $election->id, 'voting-rule-test-v1',
            $opensAt ?? now()->subMinute()->toDateTimeString(),
            $closesAt ?? now()->addHour()->toDateTimeString(), $recorder,
        );
        return [$election->fresh(), $evidence];
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(['slug' => $slug], [
            'name_am' => $slug, 'name_en' => $slug,
            'description_am' => $slug, 'description_en' => $slug,
            'category' => 'governance', 'scope' => 'tenant', 'tenant_id' => null,
            'application_id' => null, 'is_active' => true, 'is_system' => true,
        ]);
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
    }
}
