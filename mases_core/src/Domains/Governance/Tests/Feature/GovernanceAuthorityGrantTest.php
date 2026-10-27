<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceAuthorityGrantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $administrator;
    private User $grantee;
    private Person $administratorPerson;
    private Person $granteePerson;
    private GovernanceBody $body;
    private GovernancePosition $position;
    private GovernanceAuthorityGrantService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name_am' => 'የሙከራ ድርጅት',
            'name_en' => 'Test Tenant',
            'slug' => 'authority-test',
            'domain' => 'authority-test.example.test',
            'is_active' => true,
        ]);
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id);

        $this->administrator = $this->makeUser('admin@authority.example.test');
        $this->grantee = $this->makeUser('grantee@authority.example.test');
        $this->administratorPerson = $this->makePerson($this->administrator);
        $this->granteePerson = $this->makePerson($this->grantee);

        $permission = Permission::query()->create([
            'slug' => 'governance.body.manage',
            'name_am' => 'የአስተዳደር አካል ማስተዳደር',
            'name_en' => 'Manage governance bodies',
            'description_am' => 'የአስተዳደር አካል ማስተዳደር',
            'description_en' => 'Manage governance bodies',
            'category' => 'governance',
            'scope' => 'tenant',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => true,
        ]);
        $this->administrator->permissions()->attach($permission->id, ['assigned_at' => now()]);

        $this->body = GovernanceBody::query()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'BOARD',
            'body_type' => 'board',
            'name_am' => 'ቦርድ',
            'name_en' => 'Board',
            'is_active' => true,
        ]);
        $this->position = GovernancePosition::query()->create([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->body->id,
            'code' => 'CHAIR',
            'name_am' => 'ሰብሳቢ',
            'name_en' => 'Chairperson',
            'is_voting' => true,
            'is_officer' => true,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        GovernanceMembership::query()->create([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->body->id,
            'governance_position_id' => $this->position->id,
            'user_id' => $this->grantee->id,
            'member_name_en' => 'Delegated Member',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'end_date' => null,
            'status' => 'active',
            'is_ex_officio' => false,
        ]);

        Sanctum::actingAs($this->administrator, ['*']);
        $this->service = app(GovernanceAuthorityGrantService::class);
    }

    public function test_grant_is_limited_to_the_position_action_and_can_be_revoked(): void
    {
        $grant = $this->service->grant($this->administrator, (int) $this->tenant->id, [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->granteePerson->id,
            'action' => 'governance.membership.manage',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'effective_until' => CarbonImmutable::today()->addDays(7)->toDateString(),
            'reason' => 'Temporary, position-scoped delegation',
        ]);

        $this->assertTrue($this->service->allows(
            $this->grantee,
            (int) $this->tenant->id,
            (int) $this->body->id,
            (int) $this->position->id,
            'governance.membership.manage',
        ));
        $this->assertFalse($this->service->allows(
            $this->grantee,
            (int) $this->tenant->id,
            (int) $this->body->id,
            (int) $this->position->id,
            'governance.position.manage',
        ));

        $revoked = $this->service->revoke(
            $this->administrator,
            (int) $this->tenant->id,
            (int) $grant->id,
            'Delegation no longer required',
        );
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame($this->administrator->id, $revoked->revoked_by_user_id);
        $this->assertFalse($this->service->allows(
            $this->grantee,
            (int) $this->tenant->id,
            (int) $this->body->id,
            (int) $this->position->id,
            'governance.membership.manage',
        ));
    }

    public function test_self_delegation_and_unknown_actions_are_rejected(): void
    {
        try {
            $this->service->grant($this->administrator, (int) $this->tenant->id, [
                'governance_position_id' => (int) $this->position->id,
                'grantee_person_id' => (int) $this->administratorPerson->id,
                'action' => 'governance.membership.manage',
                'effective_from' => CarbonImmutable::today()->toDateString(),
                'reason' => 'Self delegation must fail',
            ]);
            $this->fail('Self-delegation must be denied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('grantee_person_id', $exception->errors());
        }

        try {
            $this->service->grant($this->administrator, (int) $this->tenant->id, [
                'governance_position_id' => (int) $this->position->id,
                'grantee_person_id' => (int) $this->granteePerson->id,
                'action' => 'finance.payment.approve',
                'effective_from' => CarbonImmutable::today()->toDateString(),
                'reason' => 'Financial authority is explicitly out of scope',
            ]);
            $this->fail('An unapproved financial action must be denied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('action', $exception->errors());
        }
    }

    private function makeUser(string $email): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Authority',
            'father_name_en' => 'Test',
            'email' => $email,
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
        $person = new Person([
            'first_name_en' => 'Authority',
            'father_name_en' => 'Test',
        ]);
        $person->user_id = $user->id;
        $person->status = 'active';
        $person->save();
        return $person;
    }
}
