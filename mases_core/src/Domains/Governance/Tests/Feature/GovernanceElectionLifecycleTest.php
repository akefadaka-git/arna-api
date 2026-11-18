<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Tests\TestCase;

final class GovernanceElectionLifecycleTest extends TestCase
{
    use RefreshDatabase;

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

        $authority = [
            'authority_reference' => 'GA-RESOLUTION-0001',
            'authority_evidence_digest' => str_repeat('a', 64),
            'authority_version' => '1.0',
            'rule_set_version' => '1.0',
            'rule_set_digest' => str_repeat('b', 64),
            'authority_snapshot' => ['instrument' => 'verified-reference'],
        ];
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority evidence reviewed', ['authority:GA-RESOLUTION-0001'], $authority);
        $this->assertSame('authorized', $election->status);
        $this->assertSame(2, (int) $election->version);
        $this->assertSame((int) $actor->id, (int) $election->authorized_by);

        $election = $service->transition(
            (int) $tenant->id,
            (int) $election->id,
            $actor,
            'schedule',
            2,
            'Schedule approved by authority',
            ['schedule:board-election-2027'], 
            [],
            ['scheduled_start_at' => '2027-01-10 09:00:00', 'scheduled_end_at' => '2027-01-10 17:00:00', 'timezone' => 'Africa/Addis_Ababa'],
        );
        $this->assertSame('scheduled', $election->status);
        $this->assertSame(3, (int) $election->version);
        $this->assertSame(2, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
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
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority evidence missing', ['authority:missing']
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
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-TZ',
            'authority_evidence_digest' => str_repeat('c', 64),
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('d', 64),
        ];
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority verified', ['authority:GA-RESOLUTION-TZ'], $authority);
        $election = $service->transition(
            (int) $tenant->id, (int) $election->id, $actor, 'schedule', 2,
            'Schedule approved', ['schedule:tz-normalization'], [],
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
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Draft evidence rejected', ['authority:draft-file'], $authority);
            $this->fail('A draft evidence file must not authorize an election.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_document_id', $exception->errors());
        }
        $authority['authority_document_id'] = $foreignFile->id;
        try {
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Foreign evidence rejected', ['authority:foreign-file'], $authority);
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
            $service->transition((int) $tenant->id, (int) $election->id, $actor, 'suspend', 0, 'Stale version attempt', ['audit:stale-version']);
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
        $authority = [
            'authority_reference' => 'GA-RESOLUTION-FULL',
            'authority_evidence_digest' => str_repeat('1', 64),
            'authority_version' => '1',
            'rule_set_version' => '1',
            'rule_set_digest' => str_repeat('2', 64),
        ];
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, 'Authority verified', ['authority:GA-RESOLUTION-FULL'], $authority);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'schedule', 2, 'Schedule approved', ['schedule:full-lifecycle'], [], [
            'scheduled_start_at' => '2027-01-10 09:00:00',
            'scheduled_end_at' => '2027-01-10 17:00:00',
            'timezone' => 'Africa/Addis_Ababa',
        ]);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'suspend', 3, 'Legal review pending', ['decision:suspend']);
        $this->assertSame('suspended', $election->status);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'resume', 4, 'Suspension condition resolved', ['decision:resume']);
        $this->assertSame('scheduled', $election->status);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'cancel', 5, 'Election cancelled by authority', ['decision:cancel']);
        $this->assertSame('cancelled', $election->status);
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'archive', 6, 'Cancellation record archived', ['decision:archive']);
        $this->assertSame('archived', $election->status);
        $this->assertSame(7, (int) $election->version);
        $history = DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->orderBy('sequence')->get();
        $this->assertCount(6, $history);
        $this->assertSame([1, 2, 3, 4, 5, 6], $history->pluck('sequence')->all());
        $this->assertSame(['authorize', 'schedule', 'suspend', 'resume', 'cancel', 'archive'], $history->pluck('action')->all());
        $this->assertTrue($history->every(fn ($row) => is_string($row->reason) && strlen($row->reason) >= 5));
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
            'governance.election.manage',
            'governance.election.authorize',
            'governance.election.schedule',
            'governance.election.suspend',
            'governance.election.cancel',
            'governance.election.archive',
            'file.view',
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
