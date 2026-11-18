<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCandidateService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class GovernanceElectionCandidateIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $manager;
    private int $electionId;
    private string $ruleDigest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::query()->create([
            'name_am' => 'የእጩ ምርጫ ሙከራ',
            'name_en' => 'Candidate Intake Test',
            'slug' => 'candidate-intake-' . uniqid(),
            'domain' => 'candidate-intake-' . uniqid() . '.example.test',
            'is_active' => true,
        ]);
        app(TenantContextService::class)->setTenantById((int) $this->tenant->id);
        $this->manager = $this->makeUser('candidate-manager');
        $this->grant($this->manager, 'governance.election.manage');
        $this->grant($this->manager, 'governance.election.authorize');
        $this->grant($this->manager, 'governance.election.view');
        $this->grant($this->manager, 'file.view');
        Auth::login($this->manager);

        $election = app(GovernanceElectionLifecycleService::class)->create((int) $this->tenant->id, $this->manager, [
            'code' => 'CANDIDATE-' . $this->tenant->id,
            'election_scope' => 'board',
            'election_type' => 'board',
            'title_en' => 'Candidate Intake Election',
        ]);
        $this->ruleDigest = str_repeat('a', 64);
        app(GovernanceElectionLifecycleService::class)->transition(
            (int) $this->tenant->id,
            (int) $election->id,
            $this->manager,
            'authorize',
            1,
            'Authority and nomination rules verified',
            ['authority:GA-RESOLUTION-CANDIDATE'],
            [
                'authority_reference' => 'GA-RESOLUTION-CANDIDATE',
                'authority_evidence_digest' => str_repeat('b', 64),
                'authority_version' => '1.0',
                'rule_set_version' => 'NOM-1.0',
                'rule_set_digest' => $this->ruleDigest,
            ],
        );
        $this->electionId = (int) $election->id;
        $service = app(GovernanceElectionCandidateService::class);
        $service->configureNominationWindow(
            (int) $this->tenant->id, $this->electionId, $this->manager,
            now()->subMinute()->toIso8601String(),
            now()->addDay()->toIso8601String(),
            'NOM-1.0',
            $this->ruleDigest,
            'GA-RESOLUTION-CANDIDATE',
        );
        $service->openNominationWindow((int) $this->tenant->id, $this->electionId, $this->manager, 1);
    }

    public function test_active_shareholder_can_nominate_a_non_shareholder_person_and_idempotency_is_immutable(): void
    {
        $nominator = $this->makeUser('nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $shareholderId = $this->makeShareholder($nominator, $nominatorPerson, 'individual');

        $candidateAccount = $this->makeUser('candidate');
        $candidatePerson = $this->makePerson($candidateAccount);

        Auth::login($nominator);
        $service = app(GovernanceElectionCandidateService::class);
        $first = $service->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'nomination-request-001', 'I nominate this candidate.',
        );
        $repeat = $service->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'nomination-request-001', 'I nominate this candidate.',
        );

        $this->assertSame((int) $first->id, (int) $repeat->id);
        $this->assertSame($shareholderId, (int) $first->nominated_by_shareholder_id);
        $this->assertSame(0, (int) $first->self_nomination);
        $this->assertSame(1, DB::table('governance_election_nomination_submissions')
            ->where('candidate_id', $first->candidate_id)->count());
        $this->assertSame('intake_pending', DB::table('governance_election_candidates')
            ->where('id', $first->candidate_id)->value('status'));

        try {
            $service->submitNomination(
                (int) $this->tenant->id, $this->electionId, $nominator,
                (int) $candidatePerson->id, 'nomination-request-001', 'Changed statement.',
            );
            $this->fail('An idempotency key must not be reused for a different payload.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, DB::table('governance_election_nomination_submissions')
                ->where('candidate_id', $first->candidate_id)->count());
        }
    }

    public function test_direct_self_nomination_is_derived_from_canonical_person_and_direct_account(): void
    {
        $candidateAccount = $this->makeUser('self-candidate');
        $candidatePerson = $this->makePerson($candidateAccount);
        $this->makeShareholder($candidateAccount, $candidatePerson, 'individual');

        Auth::login($candidateAccount);
        $submission = app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $candidateAccount,
            (int) $candidatePerson->id, 'self-nomination-001', 'I submit my own candidacy.',
        );

        $this->assertSame(1, (int) $submission->self_nomination);
    }

    public function test_nomination_correction_and_withdrawal_are_append_only(): void
    {
        $nominator = $this->makeUser('correction-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $this->makeShareholder($nominator, $nominatorPerson, 'individual');
        $candidatePerson = $this->makePerson($this->makeUser('correction-candidate'));
        Auth::login($nominator);
        $service = app(GovernanceElectionCandidateService::class);
        $submission = $service->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'correction-nomination-001', 'Original nomination statement.',
        );

        $service->correctNomination(
            (int) $this->tenant->id, $this->electionId, (int) $submission->id,
            $nominator, 'Corrected nomination statement.', 'Correcting a factual detail.',
        );
        $this->assertSame('Original nomination statement.', DB::table('governance_election_nomination_submissions')
            ->where('id', $submission->id)->value('statement'));
        $correction = DB::table('governance_election_nomination_events')
            ->where('nomination_submission_id', $submission->id)->where('event_type', 'corrected')->first();
        $this->assertNotNull($correction);
        $this->assertSame('Corrected nomination statement.', json_decode($correction->payload, true)['statement']);

        $service->withdrawNomination(
            (int) $this->tenant->id, $this->electionId, (int) $submission->id,
            $nominator, 'Nominator withdraws this submission.',
        );
        $this->assertSame('submitted', DB::table('governance_election_nomination_submissions')
            ->where('id', $submission->id)->value('status'));
        $this->assertSame(3, DB::table('governance_election_nomination_events')
            ->where('nomination_submission_id', $submission->id)->count());
        $this->assertSame('intake_pending', DB::table('governance_election_candidates')
            ->where('id', $submission->candidate_id)->value('status'));
    }

    public function test_legal_entity_representative_nomination_authority_is_not_assumed(): void
    {
        $representative = $this->makeUser('legal-entity-representative');
        DB::table('shareholders')->insert([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'company',
            'user_id' => null,
            'representative_user_id' => $representative->id,
            'person_id' => null,
            'is_active' => true,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $candidate = $this->makePerson($this->makeUser('rep-candidate'));
        $this->expectException(AuthorizationException::class);
        app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $representative,
            (int) $candidate->id, 'legal-rep-nomination-001', 'Representative nomination attempt.',
        );
    }

    public function test_inactive_or_cross_tenant_candidate_person_is_rejected(): void
    {
        $nominator = $this->makeUser('inactive-test-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $this->makeShareholder($nominator, $nominatorPerson, 'individual');

        $candidate = $this->makePerson($this->makeUser('inactive-candidate'));
        DB::table('people')->where('id', $candidate->id)->update(['status' => 'inactive']);
        Auth::login($nominator);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidate->id, 'inactive-candidate-001', null,
        );
    }

    public function test_candidate_consent_and_eligibility_decision_require_evidence_and_unconflicted_committee_member(): void
    {
        $nominator = $this->makeUser('decision-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $this->makeShareholder($nominator, $nominatorPerson, 'individual');
        $candidateAccount = $this->makeUser('decision-candidate');
        $candidatePerson = $this->makePerson($candidateAccount);

        Auth::login($nominator);
        $submission = app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'decision-nomination-001', 'Candidate nomination.',
        );

        Auth::login($candidateAccount);
        $candidate = app(GovernanceElectionCandidateService::class)->recordConsent(
            (int) $this->tenant->id, $this->electionId, (int) $submission->candidate_id,
            $candidateAccount, 'direct',
        );
        $this->assertSame('accepted', $candidate->consent_status);

        Auth::login($this->manager);
        $file = File::query()->create([
            'user_id' => $this->manager->id,
            'tenant_id' => $this->tenant->id,
            'original_name' => 'candidate-qualification.pdf',
            'name' => 'candidate-qualification-' . uniqid() . '.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 128,
            'path' => 'tenant_' . $this->tenant->id . '/candidate-qualification.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);
        $evidence = app(GovernanceElectionCandidateService::class)->attachEvidence(
            (int) $this->tenant->id, $this->electionId, (int) $candidate->id,
            $this->manager, (int) $file->id, 'qualification',
        );

        $committeeUser = $this->makeUser('committee-reviewer');
        $committeePerson = $this->makePerson($committeeUser);
        $this->grant($committeeUser, 'governance.election.committee.decide');
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $committeePerson->id,
            'user_id' => $committeeUser->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'appointed_by' => $this->manager->id,
            'recused_at' => null,
            'recusal_scope' => null,
            'appointment_reference' => 'GA-RESOLUTION-COMMITTEE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::login($committeeUser);

        $decided = app(GovernanceElectionCandidateService::class)->decideEligibility(
            (int) $this->tenant->id, $this->electionId, (int) $candidate->id,
            $committeeUser, (int) $candidate->version, 'eligible',
            'Verified against the approved nomination rule set.', [(int) $evidence->id],
        );
        $this->assertSame('eligible', $decided->status);
        $this->assertSame(1, DB::table('governance_election_candidate_decisions')
            ->where('candidate_id', $candidate->id)->count());
        $this->assertSame(4, DB::table('governance_election_candidate_events')
            ->where('candidate_id', $candidate->id)->count());
    }

    private function makeUser(string $suffix): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Candidate',
            'father_name_en' => 'Test',
            'email' => $suffix . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $this->tenant->id,
        ]);
        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        return $user;
    }

    private function makePerson(User $user): Person
    {
        $person = new Person(['first_name_en' => 'Candidate', 'father_name_en' => 'Test']);
        $person->user_id = $user->id;
        $person->status = 'active';
        $person->save();
        PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]);
        return $person;
    }

    private function makeShareholder(User $user, Person $person, string $type): int
    {
        return (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => $type,
            'user_id' => $type === 'individual' ? $user->id : null,
            'representative_user_id' => $type === 'individual' ? null : $user->id,
            'person_id' => $type === 'individual' ? $person->id : null,
            'is_active' => true,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name_am' => $slug,
                'name_en' => $slug,
                'description_am' => $slug,
                'description_en' => $slug,
                'category' => 'governance',
                'scope' => 'tenant',
                'tenant_id' => null,
                'application_id' => null,
                'is_active' => true,
                'is_system' => true,
            ],
        );
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
    }
}
