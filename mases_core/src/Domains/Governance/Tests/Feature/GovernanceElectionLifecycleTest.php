<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Tests\TestCase;

final class GovernanceElectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_create_authorize_and_schedule_are_versioned_and_recorded(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);

        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'BOARD-2026',
            'election_scope' => 'board',
            'election_type' => 'board',
            'title_am' => 'የቦርድ ምርጫ',
            'title_en' => 'Board election',
        ]);

        $this->assertSame((int) $tenant->id, (int) $election->tenant_id);
        $this->assertSame('draft', $election->status);
        $this->assertSame('share_company', $election->governance_model);
        $this->assertSame('shareholders_on_record_date', $election->electorate_basis);
        $this->assertSame('eligible_shares', $election->vote_weight_basis);
        $this->assertSame('secret', $election->ballot_secrecy);
        $this->assertSame(1, (int) $election->version);

        $authorityBytes = "%PDF-1.4\nARNA GA resolution 0001 authority evidence\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\nARNA board election rule set v1.0\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-0001',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1.0',
            'rule_set_version' => '1.0',
            'rule_set_digest' => hash('sha256', $ruleBytes),
            'rule_set_document_id' => $ruleFile->id,
            'authority_snapshot' => ['instrument' => 'verified-reference'],
        ];
        $authority['authority_evidence_id'] = $this->verifyAuthorityEvidence($tenant, $actor, $election->id, $authorityFile);
        // The HTTP/auth guard principal may differ from the explicit lifecycle actor.
        // Evidence lookup must authorize the supplied actor, not silently fall back
        // to whichever user happens to be authenticated in the current request.
        $guardUser = User::query()->create([
            'first_name_en' => 'Guard',
            'father_name_en' => 'Context',
            'email' => 'guard-context-' . uniqid() . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $guardUser->tenants()->attach($tenant->id, ['joined_at' => now()]);
        Auth::login($guardUser);

        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority evidence reviewed', [$this->makeEvidenceReference($tenant, $actor)], $authority);
        $this->assertSame('authorized', $election->status);
        $this->assertSame(2, (int) $election->version);
        $this->assertSame((int) $actor->id, (int) $election->authorized_by);
        $this->assertSame('1', $election->authority_snapshot['schema_version']);
        $this->assertSame((int) $authorityFile->id, $election->authority_snapshot['authority']['document_id']);
        $this->assertSame(hash('sha256', $authorityBytes), $election->authority_snapshot['authority']['sha256']);
        $this->assertSame((int) $ruleFile->id, $election->authority_snapshot['rule_set']['document_id']);
        $this->assertSame((int) $actor->id, $election->authority_snapshot['verified_by']);
        $this->assertArrayNotHasKey('instrument', $election->authority_snapshot);

        $election = $service->transition(
            (int) $tenant->id,
            (int) $election->id,
            $actor,
            'schedule',
            2,
            'Schedule approved by authority',
            [$this->makeEvidenceReference($tenant, $actor)], 
            [],
            ['scheduled_start_at' => '2027-01-10T09:00:00+03:00', 'scheduled_end_at' => '2027-01-10T17:00:00+03:00', 'timezone' => 'Africa/Addis_Ababa'],
        );
        $this->assertSame('scheduled', $election->status);
        $this->assertSame(3, (int) $election->version);
        $this->assertSame(2, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_http_api_validates_required_documents_and_authorizes_from_published_bytes(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);

        $headers = ['X-Tenant-ID' => (string) $tenant->id];
        $created = $this->actingAs($actor, 'sanctum')
            ->withHeaders($headers)
            ->postJson('/api/governance/elections', [
                'code' => 'HTTP-BOARD-2026',
                'election_scope' => 'board',
                'election_type' => 'board',
                'title_en' => 'HTTP lifecycle integration',
            ]);

        $created->assertCreated()->assertJsonPath('data.status', 'draft');
        $electionId = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $electionId);

        $missingDocuments = $this->actingAs($actor, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/governance/elections/{$electionId}/transitions", [
                'action' => 'authorize',
                'expected_version' => 1,
                'reason' => 'API evidence validation',
                'evidence_references' => [$this->makeEvidenceReference($tenant, $actor)],
                'authority' => [
                    'authority_reference' => 'HTTP-RESOLUTION',
                    'authority_evidence_digest' => str_repeat('a', 64),
                    'authority_version' => '1',
                    'rule_set_version' => '1',
                    'rule_set_digest' => str_repeat('b', 64),
                ],
            ]);

        $missingDocuments->assertUnprocessable()->assertJsonValidationErrors([
            'authority.authority_document_id',
            'authority.authority_evidence_id',
            'authority.rule_set_document_id',
        ]);
        $this->assertSame('draft', app(GovernanceElectionLifecycleService::class)
            ->get((int) $tenant->id, $electionId)->status);
        $this->assertSame(
            0,
            DB::table('governance_election_lifecycle_transitions')->where('election_id', $electionId)->count()
        );

        $authorityBytes = "%PDF-1.4\\nHTTP authority source bytes\\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\\nHTTP rule-set source bytes\\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authorityEvidenceId = $this->verifyAuthorityEvidence($tenant, $actor, $electionId, $authorityFile);

        $authorized = $this->actingAs($actor, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/governance/elections/{$electionId}/transitions", [
                'action' => 'authorize',
                'expected_version' => 1,
                'reason' => 'API evidence verified',
                'evidence_references' => [$this->makeEvidenceReference($tenant, $actor)],
                'authority' => [
                    'authority_reference' => 'HTTP-RESOLUTION',
                    'authority_document_id' => $authorityFile->id,
                    'authority_evidence_id' => $authorityEvidenceId,
                    'authority_evidence_digest' => hash('sha256', $authorityBytes),
                    'authority_version' => '1',
                    'rule_set_version' => '1',
                    'rule_set_document_id' => $ruleFile->id,
                    'rule_set_digest' => hash('sha256', $ruleBytes),
                ],
            ]);

        $authorized->assertOk()->assertJsonPath('data.status', 'authorized');
        $this->assertSame((int) $actor->id, (int) $authorized->json('data.authorized_by'));
        $this->assertSame(
            1,
            DB::table('governance_election_lifecycle_transitions')->where('election_id', $electionId)->count()
        );
    }

    public function test_authorization_fails_closed_without_versioned_authority_evidence(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'BOARD-UNVERIFIED',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority evidence missing', [$this->makeEvidenceReference($tenant, $actor)]
            );
            $this->fail('An election without authority evidence must not be authorized.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_reference', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_timezone_offset_inputs_are_normalized_to_the_selected_iana_zone(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'TZ-NORMALIZE',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authorityBytes = "%PDF-1.4\nARNA timezone test authority evidence\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\nARNA timezone test rule set\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-TZ',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => hash('sha256', $ruleBytes),
            'rule_set_document_id' => $ruleFile->id,
        ];
        $authority['authority_evidence_id'] = $this->verifyAuthorityEvidence($tenant, $actor, $election->id, $authorityFile);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority verified', [$this->makeEvidenceReference($tenant, $actor)], $authority);
        $election = $service->transition(
            (int) $tenant->id, (int) $election->id, $actor, 'schedule', 2,
            'Schedule approved', [$this->makeEvidenceReference($tenant, $actor)], [],
            [
                'scheduled_start_at' => '2027-01-10T09:00:00+00:00',
                'scheduled_end_at' => '2027-01-10T17:00:00+00:00',
                'timezone' => 'Africa/Addis_Ababa',
            ],
        );
        $stored = DB::table('governance_elections')->where('id', $election->id)->first();
        $this->assertSame('2027-01-10 12:00:00', $stored->scheduled_start_at);
        $this->assertSame('2027-01-10 20:00:00', $stored->scheduled_end_at);
        $this->assertSame('Africa/Addis_Ababa', $stored->timezone);
    }

    public function test_arbitrary_transition_evidence_references_are_rejected(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'UNRESOLVED-EVIDENCE',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id,
                (int) $election->id,
                $actor,
                'cancel',
                1,
                'Reject arbitrary evidence',
                ['authority:free-text-is-not-evidence'],
            );
            $this->fail('Free-text references must not be accepted as durable transition evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_references.0', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_schedule_rejects_relative_and_invalid_calendar_date_times(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'STRICT-SCHEDULE',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authorityBytes = "%PDF-1.4\\nStrict schedule authority\\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\\nStrict schedule rules\\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authority = [
            'authority_reference' => 'STRICT-SCHEDULE-AUTH',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => hash('sha256', $ruleBytes),
            'rule_set_document_id' => $ruleFile->id,
        ];
        $authority['authority_evidence_id'] = $this->verifyAuthorityEvidence($tenant, $actor, $election->id, $authorityFile);
        $election = $service->transition(
            (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1,
            'Strict schedule authority verified', [$this->makeEvidenceReference($tenant, $actor)], $authority
        );

        foreach ([
            ['start' => 'tomorrow 9am', 'end' => 'tomorrow 5pm'],
            ['start' => '2027-02-30T09:00:00+03:00', 'end' => '2027-03-01T17:00:00+03:00'],
            ['start' => '2027-01-10 09:00:00', 'end' => '2027-01-10 17:00:00'],
        ] as $case) {
            try {
                $service->transition(
                    (int) $tenant->id, (int) $election->id, $actor, 'schedule', (int) $election->version,
                    'Reject ambiguous schedule', [$this->makeEvidenceReference($tenant, $actor)], [],
                    [
                        'scheduled_start_at' => $case['start'],
                        'scheduled_end_at' => $case['end'],
                        'timezone' => 'Africa/Addis_Ababa',
                    ]
                );
                $this->fail('Relative, invalid, or timezone-free schedule inputs must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertTrue(
                    isset($exception->errors()['scheduled_start_at']) || isset($exception->errors()['scheduled_end_at'])
                );
            }
        }

        $this->assertSame('authorized', $election->fresh()->status);
        $this->assertSame(2, (int) $election->fresh()->version);
    }

    public function test_authority_file_must_be_published_and_belong_to_the_same_tenant(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'AUTH-FILE-TENANT',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $otherTenant = Tenant::query()->create([
            'name_am' => 'ሌላ ተቋም',
            'name_en' => 'Other Tenant',
            'slug' => 'other-election-tenant-' . uniqid(),
            'domain' => 'other-election-' . uniqid() . '.example.test',
            'is_active' => true,
        ]);
        $draftFile = File::query()->create([
            'user_id' => $actor->id, 'tenant_id' => $tenant->id,
            'original_name' => 'draft-authority.pdf', 'name' => 'draft-authority.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 100,
            'path' => 'tenant_' . $tenant->id . '/draft-authority.pdf',
            'disk' => 'local', 'version' => 1, 'status' => 'draft',
            'is_public' => false,
        ]);
        $foreignFile = File::query()->create([
            'user_id' => $actor->id, 'tenant_id' => $otherTenant->id,
            'original_name' => 'authority.pdf', 'name' => 'foreign-authority.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 100,
            'path' => 'tenant_' . $otherTenant->id . '/foreign-authority.pdf',
            'disk' => 'local', 'version' => 1, 'status' => 'published',
            'is_public' => false,
        ]);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-FILE',
            'authority_evidence_digest' => str_repeat('e', 64),
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('f', 64),
            'authority_document_id' => $foreignFile->id,
        ];
        $authority['authority_document_id'] = $draftFile->id;
        try {
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Draft evidence rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority);
            $this->fail('A draft evidence file must not authorize an election.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_document_id', $exception->errors());
        }
        $authority['authority_document_id'] = $foreignFile->id;
        try {
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Foreign evidence rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority);
            $this->fail('A file from another tenant must not authorize this election.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_document_id', $exception->errors());
        }
        $this->assertSame('draft', $election->fresh()->status);
    }

    public function test_every_lifecycle_transition_requires_a_reason_and_stale_versions_fail_closed(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'REASON-REQUIRED',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        try {
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, null);
            $this->fail('A lifecycle transition without a reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        try {
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Reason without evidence');
            $this->fail('Every lifecycle transition must carry an evidence reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_references', $exception->errors());
        }
        try {
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'suspend', 0, 'Stale version attempt', [$this->makeEvidenceReference($tenant, $actor)]);
            $this->fail('A stale expected version must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version', $exception->errors());
        }
        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_suspend_resume_cancel_archive_follow_the_guarded_state_machine(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'FULL-LIFECYCLE',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authorityBytes = "%PDF-1.4\nARNA full lifecycle authority evidence\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\nARNA full lifecycle rule set\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-FULL',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => hash('sha256', $ruleBytes),
            'rule_set_document_id' => $ruleFile->id,
        ];
        $authority['authority_evidence_id'] = $this->verifyAuthorityEvidence($tenant, $actor, $election->id, $authorityFile);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority verified', [$this->makeEvidenceReference($tenant, $actor)], $authority);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'schedule', 2, 'Schedule approved', [$this->makeEvidenceReference($tenant, $actor)], [], [
            'scheduled_start_at' => '2027-01-10T09:00:00+03:00',
            'scheduled_end_at' => '2027-01-10T17:00:00+03:00',
            'timezone' => 'Africa/Addis_Ababa',
        ]);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'suspend', 3, 'Legal review pending', [$this->makeEvidenceReference($tenant, $actor)]);
        $this->assertSame('suspended', $election->status);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'resume', 4, 'Suspension condition resolved', [$this->makeEvidenceReference($tenant, $actor)]);
        $this->assertSame('scheduled', $election->status);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'cancel', 5, 'Election cancelled by authority', [$this->makeEvidenceReference($tenant, $actor)]);
        $this->assertSame('cancelled', $election->status);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'archive', 6, 'Cancellation record archived', [$this->makeEvidenceReference($tenant, $actor)]);
        $this->assertSame('archived', $election->status);
        $this->assertSame(7, (int) $election->version);
        $history = DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->orderBy('sequence')->get();
        $this->assertCount(6, $history);
        $this->assertSame([1, 2, 3, 4, 5, 6], $history->pluck('sequence')->all());
        $this->assertSame(['authorize', 'schedule', 'suspend', 'resume', 'cancel', 'archive'], $history->pluck('action')->all());
        $this->assertTrue($history->every(fn ($row) => is_string($row->reason) && strlen($row->reason) >= 5));
    }

    public function test_authorization_requires_a_published_authority_document_id(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'AUTH-DOC-REQUIRED',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-NO-DOC',
            'authority_evidence_digest' => str_repeat('a', 64),
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('b', 64),
        ];

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1,
                'Missing source document rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority
            );
            $this->fail('A digest without a linked authority document must not authorize an election.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_document_id', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_authorization_requires_a_published_rule_set_document(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'RULE-DOC-REQUIRED',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authorityBytes = "%PDF-1.4\nValid authority document\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-RULE-DOC',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('b', 64),
        ];

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1,
                'Missing rule-set source rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority
            );
            $this->fail('A rule-set digest without a linked source document must not authorize an election.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rule_set_document_id', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_authorization_recomputes_and_rejects_a_mismatched_rule_set_digest(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'RULE-DIGEST-MISMATCH',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authorityBytes = "%PDF-1.4\nValid authority document\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\nActual rule-set bytes\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-RULE-MISMATCH',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('f', 64),
            'rule_set_document_id' => $ruleFile->id,
        ];

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1,
                'Mismatched rule-set digest rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority
            );
            $this->fail('A rule-set digest that does not match stored bytes must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rule_set_digest', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_authorization_recomputes_and_rejects_a_mismatched_authority_digest(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'AUTH-DIGEST-MISMATCH',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $bytes = "%PDF-1.4\nActual authority bytes\n";
        $file = $this->makePublishedAuthorityFile($tenant, $actor, $bytes);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-MISMATCH',
            'authority_evidence_digest' => str_repeat('f', 64),
            'authority_document_id' => $file->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('b', 64),
        ];

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1,
                'Mismatched authority digest rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority
            );
            $this->fail('A caller-supplied digest that does not match stored bytes must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_evidence_digest', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }

    public function test_authorization_fails_closed_when_authority_storage_bytes_are_unavailable(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $actor, [
            'code' => 'AUTH-STORAGE-MISSING',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $bytes = "%PDF-1.4\nExpected but unavailable authority bytes\n";
        $file = File::query()->create([
            'user_id' => $actor->id,
            'tenant_id' => $tenant->id,
            'original_name' => 'missing-authority.pdf',
            'name' => 'missing-authority.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($bytes),
            'path' => 'tenant_' . $tenant->id . '/missing-authority.pdf',
            'disk' => $disk,
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-STORAGE',
            'authority_evidence_digest' => hash('sha256', $bytes),
            'authority_document_id' => $file->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('b', 64),
        ];

        try {
            app(GovernanceElectionLifecycleService::class)->transition(
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1,
                'Unavailable authority bytes rejected', [$this->makeEvidenceReference($tenant, $actor)], $authority
            );
            $this->fail('An authority file whose stored bytes cannot be read must not authorize an election.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_evidence_digest', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
    }


    public function test_unexpected_storage_exception_rolls_back_authorization_atomically(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->create((int) $tenant->id, $actor, [
            'code' => 'AUTH-STORAGE-THROWABLE',
            'election_scope' => 'board',
            'election_type' => 'board',
        ]);
        $authorityBytes = "%PDF-1.4\\nAuthority bytes for storage exception\\n";
        $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
        $ruleBytes = "%PDF-1.4\\nRule bytes for storage exception\\n";
        $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
        $authority = [
            'authority_reference' => 'GA-STORAGE-THROWABLE',
            'authority_evidence_digest' => hash('sha256', $authorityBytes),
            'authority_document_id' => $authorityFile->id,
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => hash('sha256', $ruleBytes),
            'rule_set_document_id' => $ruleFile->id,
        ];

        $transitionEvidence = $this->makeEvidenceReference($tenant, $actor, 'public');
        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andThrow(new \RuntimeException('Unexpected storage adapter failure'));

        try {
            $service->transition(
                (int) $tenant->id,
                (int) $election->id,
                $actor,
                'authorize',
                1,
                'Unexpected storage failure must roll back',
                [$transitionEvidence],
                $authority
            );
            $this->fail('Unexpected storage exceptions must abort authorization.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unexpected storage adapter failure', $exception->getMessage());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(1, (int) $election->fresh()->version);
        $this->assertSame(
            0,
            DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count()
        );
    }

    public function test_rule_set_evidence_rejects_foreign_unpublished_deleted_and_unavailable_sources_atomically(): void
    {
        [$tenant, $actor] = $this->makeAuthorizedActor();
        Auth::login($actor);
        $service = app(GovernanceElectionLifecycleService::class);

        foreach (['foreign', 'unpublished', 'deleted', 'storage_missing'] as $case) {
            $election = $service->create((int) $tenant->id, $actor, [
                'code' => 'RULE-SOURCE-' . strtoupper($case),
                'election_scope' => 'board',
                'election_type' => 'board',
            ]);

            $authorityBytes = "%PDF-1.4\\nValid authority for {$case}\\n";
            $authorityFile = $this->makePublishedAuthorityFile($tenant, $actor, $authorityBytes);
            $ruleBytes = "%PDF-1.4\\nRule-set source for {$case}\\n";

            if ($case === 'foreign') {
                $otherTenant = Tenant::query()->create([
                    'name_am' => 'ሌላ ተቋም',
                    'name_en' => 'Foreign Rule Tenant',
                    'slug' => 'foreign-rule-' . uniqid(),
                    'domain' => 'foreign-rule-' . uniqid() . '.example.test',
                    'is_active' => true,
                ]);
                $ruleFile = $this->makePublishedAuthorityFile($otherTenant, $actor, $ruleBytes);
            } else {
                $ruleFile = $this->makePublishedAuthorityFile($tenant, $actor, $ruleBytes);
                if ($case === 'unpublished') {
                    $ruleFile->status = 'draft';
                    $ruleFile->save();
                } elseif ($case === 'deleted') {
                    $ruleFile->delete();
                } elseif ($case === 'storage_missing') {
                    Storage::disk('local')->delete($ruleFile->path);
                }
            }

            $authority = [
                'authority_reference' => 'GA-RULE-SOURCE-' . strtoupper($case),
                'authority_evidence_digest' => hash('sha256', $authorityBytes),
                'authority_document_id' => $authorityFile->id,
                'authority_version' => '1',
                'rule_set_version' => '1',
                'rule_set_digest' => hash('sha256', $ruleBytes),
                'rule_set_document_id' => $ruleFile->id,
            ];

            try {
                $service->transition(
                    (int) $tenant->id,
                    (int) $election->id,
                    $actor,
                    'authorize',
                    1,
                    'Invalid rule-set source rejected',
                    [$this->makeEvidenceReference($tenant, $actor)],
                    $authority
                );
                $this->fail("A {$case} rule-set source must not authorize an election.");
            } catch (ValidationException $exception) {
                $expectedField = $case === 'storage_missing' ? 'rule_set_digest' : 'rule_set_document_id';
                $this->assertArrayHasKey($expectedField, $exception->errors(), "Unexpected validation field for {$case} source.");
            }

            $this->assertSame('draft', $election->fresh()->status);
            $this->assertSame(1, (int) $election->fresh()->version);
            $this->assertSame(
                0,
                DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count()
            );
        }
    }

    private function verifyAuthorityEvidence(Tenant $tenant, User $reviewer, int $electionId, File $document): int
    {
        $recorder = User::query()->create([
            'first_name_en' => 'Authority',
            'father_name_en' => 'Recorder',
            'email' => 'authority-recorder-' . uniqid() . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $recorder->tenants()->attach($tenant->id, ['joined_at' => now()]);
        foreach (['governance.election.manage', 'file.view', 'file.download'] as $slug) {
            $permission = Permission::query()->firstOrCreate(['slug' => $slug], [
                'name_am' => $slug, 'name_en' => $slug, 'description_am' => $slug, 'description_en' => $slug,
                'category' => 'governance', 'scope' => 'tenant', 'tenant_id' => null,
                'application_id' => null, 'is_active' => true, 'is_system' => true,
            ]);
            $recorder->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        $service = app(GovernanceElectionAuthorityService::class);
        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->id, $electionId, 'general_assembly_resolution',
            'TEST-AUTHORITY-' . $electionId, null, (int) $document->id, $recorder
        );
        $service->reviewAuthorityEvidence(
            (int) $tenant->id, $electionId, (int) $evidence->id,
            'reviewed', 'The instrument and published document bytes were reviewed.', $reviewer
        );
        $service->verifyAuthority(
            (int) $tenant->id, $electionId, (int) $evidence->id,
            'The verified source is the authority for this test election.', $reviewer
        );

        return (int) $evidence->id;
    }

    private function makeEvidenceReference(Tenant $tenant, User $actor, string $disk = 'local'): string
    {
        $bytes = "%PDF-1.4\\nGovernance transition evidence\\n";
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

    private function makeAuthorizedActor(): array
    {
        $tenant = Tenant::query()->create([
            'name_am' => 'የምርጫ ሙከራ',
            'name_en' => 'Election Lifecycle Test',
            'slug' => 'election-lifecycle-test-' . uniqid(),
            'domain' => 'election-lifecycle-' . uniqid() . '.example.test',
            'is_active' => true,
        ]);
        app(TenantContextService::class)->setTenantById((int) $tenant->id);
        $user = User::query()->create([
            'first_name_en' => 'Lifecycle',
            'father_name_en' => 'Tester',
            'email' => 'lifecycle-' . uniqid() . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now()]);
        foreach ([
            'governance.election.view',
            'governance.election.manage',
            'governance.election.authority.review',
            'governance.election.authority.verify',
            'governance.election.authorize',
            'governance.election.schedule',
            'governance.election.suspend',
            'governance.election.cancel',
            'governance.election.archive',
            'file.view',
            'file.download',
        ] as $slug) {
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
                ]
            );
            $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        return [$tenant, $user];
    }
}
