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
        $this->assertSame(1, (int) $election->version);

        $authority = [
            'authority_reference' => 'GA-RESOLUTION-0001',
            'authority_evidence_digest' => str_repeat('a', 64),
            'authority_version' => '1.0',
            'rule_set_version' => '1.0',
            'rule_set_digest' => str_repeat('b', 64),
            'authority_snapshot' => ['instrument' => 'verified-reference'],
        ];
        $election = $service->transition((int) $tenant->id, (int) $election->id, $actor, 'authorize', 1, null, [], $authority);
        $this->assertSame('authorized', $election->status);
        $this->assertSame(2, (int) $election->version);
        $this->assertSame((int) $actor->id, (int) $election->authorized_by);

        $election = $service->transition(
            (int) $tenant->id,
            (int) $election->id,
            $actor,
            'schedule',
            2,
            null,
            [],
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
                (int) $tenant->id, (int) $election->id, $actor, 'authorize', 1
            );
            $this->fail('An election without authority evidence must not be authorized.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authority_reference', $exception->errors());
        }

        $this->assertSame('draft', $election->fresh()->status);
        $this->assertSame(0, DB::table('governance_election_lifecycle_transitions')->where('election_id', $election->id)->count());
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
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'governance.election.manage'],
            [
                'name_am' => 'የምርጫ አስተዳደር',
                'name_en' => 'Manage elections',
                'description_am' => 'የምርጫ አስተዳደር',
                'description_en' => 'Manage elections',
                'category' => 'governance',
                'scope' => 'tenant',
                'tenant_id' => null,
                'application_id' => null,
                'is_active' => true,
                'is_system' => true,
            ]
        );
        $user->permissions()->attach($permission->id, ['assigned_at' => now()]);

        return [$tenant, $user];
    }
}
