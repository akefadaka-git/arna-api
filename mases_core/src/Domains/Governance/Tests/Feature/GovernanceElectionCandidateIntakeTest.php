<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\QueryException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCandidateService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;
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
    private int $authorityEvidenceId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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
        $this->grant($this->manager, 'governance.election.authority.review');
        $this->grant($this->manager, 'governance.election.authority.verify');
        $this->grant($this->manager, 'file.view');
        $this->grant($this->manager, 'file.download');
        Auth::login($this->manager);

        $election = app(GovernanceElectionLifecycleService::class)->create((int) $this->tenant->id, $this->manager, [
            'code' => 'CANDIDATE-' . $this->tenant->id,
            'election_scope' => 'board',
            'election_type' => 'board',
            'title_en' => 'Candidate Intake Election',
        ]);
        $authorityBytes = "%PDF-1.4\nCandidate intake authority evidence\n";
        $authorityFile = $this->makePublishedAuthorityFile($this->tenant, $this->manager, $authorityBytes);
        $ruleBytes = "%PDF-1.4\nCandidate intake nomination rules\n";
        $this->ruleDigest = hash('sha256', $ruleBytes);
        $ruleFile = $this->makePublishedAuthorityFile($this->tenant, $this->manager, $ruleBytes);
        $this->authorityEvidenceId = $this->verifyAuthorityEvidence(
            $this->tenant, $this->manager, (int) $election->id, $authorityFile, 'GA-RESOLUTION-CANDIDATE'
        );
        app(GovernanceElectionLifecycleService::class)->transition(
            (int) $this->tenant->id,
            (int) $election->id,
            $this->manager,
            'authorize',
            1,
            'Authority and nomination rules verified',
            [$this->makeEvidenceReference($this->tenant, $this->manager)],
            [
                'authority_reference' => 'GA-RESOLUTION-CANDIDATE',
                'authority_document_id' => (int) $authorityFile->id,
                'authority_evidence_id' => $this->authorityEvidenceId,
                'authority_evidence_digest' => hash('sha256', $authorityBytes),
                'authority_version' => '1.0',
                'rule_set_version' => 'NOM-1.0',
                'rule_set_digest' => hash('sha256', $ruleBytes),
                'rule_set_document_id' => (int) $ruleFile->id,
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
        $receipt = DB::table('governance_election_nomination_submissions')->where('id', $first->id)->first();
        $this->assertSame('electronic', $receipt->submission_channel);
        $this->assertStringStartsWith('NOMINATION-RECEIPT-', $receipt->source_reference);
        $this->assertSame(1, DB::table('governance_election_nomination_submissions')
            ->where('source_reference', $receipt->source_reference)->count());
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

    public function test_mysql_unique_indexes_reject_duplicate_nomination_receipts(): void
    {
        $nominator = $this->makeUser('unique-index-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $this->makeShareholder($nominator, $nominatorPerson, 'individual');
        $candidatePerson = $this->makePerson($this->makeUser('unique-index-candidate'));

        Auth::login($nominator);
        $submission = app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'unique-index-nomination-001', 'Unique receipt test.',
        );
        $receipt = (array) DB::table('governance_election_nomination_submissions')
            ->where('id', $submission->id)->first();
        unset($receipt['id']);

        try {
            DB::table('governance_election_nomination_submissions')->insert($receipt);
            $this->fail('MySQL unique constraints must reject a duplicate nomination receipt.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('governance_election_nomination_submissions')
                ->where('tenant_id', $this->tenant->id)->where('election_id', $this->electionId)->count());
        }
    }

    public function test_candidate_declarations_are_snapshotted_and_versioned(): void
    {
        $nominator = $this->makeUser('declaration-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $this->makeShareholder($nominator, $nominatorPerson, 'individual');
        $candidateAccount = $this->makeUser('declaration-candidate');
        $candidatePerson = $this->makePerson($candidateAccount);

        Auth::login($nominator);
        $submission = app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'declaration-nomination-001', 'Candidate nomination.',
        );

        Auth::login($candidateAccount);
        $service = app(GovernanceElectionCandidateService::class);
        $first = $service->recordDeclarations(
            (int) $this->tenant->id, $this->electionId, (int) $submission->candidate_id,
            $candidateAccount, ['qualification' => 'Declared professional qualification', 'conflicts' => []], 'direct',
        );
        $second = $service->recordDeclarations(
            (int) $this->tenant->id, $this->electionId, (int) $submission->candidate_id,
            $candidateAccount, ['qualification' => 'Updated qualification declaration', 'conflicts' => []], 'direct',
        );

        $this->assertSame('Updated qualification declaration', $second->declarations['qualification']);
        $this->assertSame(3, (int) $second->version);
        $events = DB::table('governance_election_candidate_declaration_events')
            ->where('candidate_id', $submission->candidate_id)->orderBy('sequence')->get();
        $this->assertCount(2, $events);
        $this->assertSame(1, (int) $events[0]->sequence);
        $this->assertSame(2, (int) $events[1]->sequence);
        $this->assertSame($candidateAccount->id, (int) $events[1]->actor_user_id);
        $this->assertNotSame($events[0]->declaration_digest, $events[1]->declaration_digest);
        $this->assertSame('Updated qualification declaration', json_decode($events[1]->declarations, true)['qualification']);
    }

    public function test_nominator_must_resolve_to_its_own_canonical_person_and_active_tenant_access(): void
    {
        $nominator = $this->makeUser('identity-bound-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $shareholderId = $this->makeShareholder($nominator, $nominatorPerson, 'individual');
        $candidatePerson = $this->makePerson($this->makeUser('identity-bound-candidate'));
        $otherPerson = $this->makePerson($this->makeUser('identity-bound-other'));

        DB::table('shareholders')->where('id', $shareholderId)
            ->update(['person_id' => $otherPerson->id]);
        Auth::login($nominator);

        $this->expectException(AuthorizationException::class);
        app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'identity-bound-nomination-001', 'Invalid identity binding.',
        );
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
        $candidateAccount = $this->makeUser('correction-candidate');
        $candidatePerson = $this->makePerson($candidateAccount);
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

        Auth::login($candidateAccount);
        $service->recordConsent(
            (int) $this->tenant->id, $this->electionId, (int) $submission->candidate_id,
            $candidateAccount, 'direct',
        );
        Auth::login($nominator);
        $service->withdrawNomination(
            (int) $this->tenant->id, $this->electionId, (int) $submission->id,
            $nominator, 'Nominator withdraws this submission.',
        );
        $this->assertSame('submitted', DB::table('governance_election_nomination_submissions')
            ->where('id', $submission->id)->value('status'));
        $this->assertSame(3, DB::table('governance_election_nomination_events')
            ->where('nomination_submission_id', $submission->id)->count());
        $this->assertSame('accepted', DB::table('governance_election_candidates')
            ->where('id', $submission->candidate_id)->value('consent_status'));
        $this->assertSame('eligibility_pending', DB::table('governance_election_candidates')
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
            'appointment_evidence_id' => $this->authorityEvidenceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A committee member who submitted this candidate's nomination must recuse.
        $this->grant($nominator, 'governance.election.committee.decide');
        DB::table('governance_election_committee_members')->insert([
            'tenant_id' => $this->tenant->id,
            'election_id' => $this->electionId,
            'person_id' => $nominatorPerson->id,
            'user_id' => $nominator->id,
            'member_role' => 'committee_member',
            'ex_officio' => false,
            'status' => 'active',
            'appointed_at' => now(),
            'appointed_by' => $this->manager->id,
            'recused_at' => null,
            'recusal_scope' => null,
            'appointment_reference' => 'GA-RESOLUTION-NOMINATOR-COMMITTEE',
            'appointment_evidence_id' => $this->authorityEvidenceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::login($nominator);
        try {
            app(GovernanceElectionCandidateService::class)->decideEligibility(
                (int) $this->tenant->id, $this->electionId, (int) $candidate->id,
                $nominator, (int) $candidate->version, 'eligible',
                'Submitter must not decide own nomination.', [(int) $evidence->id],
            );
            $this->fail('A nomination submitter must be excluded from the candidate eligibility decision.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('governance_election_candidate_decisions')
                ->where('candidate_id', $candidate->id)->count());
        }

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

        try {
            app(GovernanceElectionCandidateService::class)->correctNomination(
                (int) $this->tenant->id, $this->electionId, (int) $submission->id,
                $nominator, 'Post-decision correction attempt.', 'Attempt after final eligibility.',
            );
            $this->fail('A final eligibility decision must freeze nomination corrections.');
        } catch (ConflictHttpException) {
            $this->assertSame(0, DB::table('governance_election_nomination_events')
                ->where('nomination_submission_id', $submission->id)
                ->where('event_type', 'corrected')->count());
        }
    }

    public function test_candidate_tenant_access_is_revalidated_at_consent_and_eligibility_decision(): void
    {
        $nominator = $this->makeUser('tenant-access-nominator');
        $nominatorPerson = $this->makePerson($nominator);
        $this->makeShareholder($nominator, $nominatorPerson, 'individual');
        $candidateAccount = $this->makeUser('tenant-access-candidate');
        $candidatePerson = $this->makePerson($candidateAccount);

        Auth::login($nominator);
        $submission = app(GovernanceElectionCandidateService::class)->submitNomination(
            (int) $this->tenant->id, $this->electionId, $nominator,
            (int) $candidatePerson->id, 'tenant-access-nomination-001', 'Candidate nomination.',
        );

        DB::table('person_tenant_accesses')->where('person_id', $candidatePerson->id)
            ->where('tenant_id', $this->tenant->id)->update(['status' => 'inactive']);
        Auth::login($candidateAccount);
        try {
            app(GovernanceElectionCandidateService::class)->recordConsent(
                (int) $this->tenant->id, $this->electionId, (int) $submission->candidate_id,
                $candidateAccount, 'direct',
            );
            $this->fail('Consent must fail when candidate tenant access has been revoked.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', DB::table('governance_election_candidates')
                ->where('id', $submission->candidate_id)->value('consent_status'));
        }

        DB::table('person_tenant_accesses')->where('person_id', $candidatePerson->id)
            ->where('tenant_id', $this->tenant->id)->update(['status' => 'active']);
        $candidate = app(GovernanceElectionCandidateService::class)->recordConsent(
            (int) $this->tenant->id, $this->electionId, (int) $submission->candidate_id,
            $candidateAccount, 'direct',
        );
        $this->assertSame('accepted', $candidate->consent_status);

        DB::table('person_tenant_accesses')->where('person_id', $candidatePerson->id)
            ->where('tenant_id', $this->tenant->id)->update(['status' => 'inactive']);
        try {
            app(GovernanceElectionCandidateService::class)->decideEligibility(
                (int) $this->tenant->id, $this->electionId, (int) $candidate->id,
                $this->manager, (int) $candidate->version, 'eligible',
                'Review must stop after tenant access is revoked.', [1],
            );
            $this->fail('Eligibility review must fail when candidate tenant access has been revoked.');
        } catch (ConflictHttpException) {
            $this->assertSame('eligibility_pending', DB::table('governance_election_candidates')
                ->where('id', $candidate->id)->value('status'));
            $this->assertSame(0, DB::table('governance_election_candidate_decisions')
                ->where('candidate_id', $candidate->id)->count());
        }
    }


    private function verifyAuthorityEvidence(Tenant $tenant, User $reviewer, int $electionId, File $document, string $authorityReference): int
    {
        $recorder = $this->makeUser('authority-recorder');
        foreach (['governance.election.manage', 'file.view', 'file.download'] as $slug) {
            $this->grant($recorder, $slug);
        }
        $service = app(GovernanceElectionAuthorityService::class);
        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->id, $electionId, 'general_assembly_resolution',
            $authorityReference, null, (int) $document->id, $recorder
        );
        $service->reviewAuthorityEvidence(
            (int) $tenant->id, $electionId, (int) $evidence->id,
            'reviewed', 'The governing instrument and published source bytes were reviewed.', $reviewer
        );
        $service->verifyAuthority(
            (int) $tenant->id, $electionId, (int) $evidence->id,
            'The verified source is authoritative for this test election.', $reviewer
        );
        return (int) $evidence->id;
    }

    private function makeEvidenceReference(Tenant $tenant, User $actor, string $disk = 'local'): string
    {
        $bytes = "%PDF-1.4\nGovernance transition evidence\n";
        $file = $this->makePublishedAuthorityFile($tenant, $actor, $bytes, $disk);
        return 'file:' . $file->id . ':' . hash('sha256', $bytes);
    }

    private function makePublishedAuthorityFile(Tenant $tenant, User $actor, string $bytes, string $disk = 'local'): File
    {
        $path = 'tenant_' . $tenant->id . '/authority-' . uniqid() . '.pdf';
        Storage::disk($disk)->put($path, $bytes);
        return File::query()->create([
            'user_id' => $actor->id,
            'tenant_id' => $tenant->id,
            'original_name' => 'authority.pdf',
            'name' => basename($path),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($bytes),
            'path' => $path,
            'disk' => $disk,
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);
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
