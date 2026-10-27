<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceSeatService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceActingAssignmentService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
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
        $grantResponse = $this->postJson(route('api.governance.authority-grants.store'), [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->granteePerson->id,
            'action' => 'governance.membership.manage',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'effective_until' => CarbonImmutable::today()->addDays(7)->toDateString(),
            'reason' => 'Temporary, position-scoped delegation',
        ])->assertCreated();
        $grantId = (int) $grantResponse->json('data.id');

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

        $revokeResponse = $this->deleteJson(route('api.governance.authority-grants.destroy', [
            'governanceAuthorityGrant' => $grantId,
        ]), ['reason' => 'Delegation no longer required'])->assertOk();
        $this->assertNotNull($revokeResponse->json('data.revoked_at'));
        $this->assertSame($this->administrator->id, $revokeResponse->json('data.revoked_by_user_id'));
        $this->assertFalse($this->service->allows(
            $this->grantee,
            (int) $this->tenant->id,
            (int) $this->body->id,
            (int) $this->position->id,
            'governance.membership.manage',
        ));
    }

    public function test_delegation_authorizes_other_membership_but_not_the_grantee_own_record(): void
    {
        $this->postJson(route('api.governance.authority-grants.store'), [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->granteePerson->id,
            'action' => 'governance.membership.manage',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'reason' => 'Manage other memberships in this position',
        ])->assertCreated();

        $target = $this->makeUser('target@authority.example.test');
        $targetMembership = GovernanceMembership::query()->create([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->body->id,
            'governance_position_id' => $this->position->id,
            'user_id' => $target->id,
            'member_name_en' => 'Target Member',
            'start_date' => CarbonImmutable::today()->toDateString(),
            'end_date' => null,
            'status' => 'active',
            'is_ex_officio' => false,
        ]);

        Sanctum::actingAs($this->grantee, ['*']);
        $this->patchJson(route('api.governance.memberships.update', [
            'governanceMembership' => $targetMembership->id,
        ]), ['member_name_en' => 'Updated by delegate'])->assertOk();

        $ownMembership = GovernanceMembership::query()
            ->where('user_id', $this->grantee->id)
            ->where('governance_position_id', $this->position->id)
            ->firstOrFail();

        $this->patchJson(route('api.governance.memberships.update', [
            'governanceMembership' => $ownMembership->id,
        ]), ['member_name_en' => 'Attempted self-change'])->assertForbidden();
    }

    public function test_position_scoped_appointment_grant_authorizes_only_the_matching_action(): void
    {
        $this->postJson(route('api.governance.authority-grants.store'), [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->granteePerson->id,
            'action' => 'governance.appointment.manage',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'reason' => 'Manage appointments for this position',
        ])->assertCreated();

        $appointment = GovernanceAppointment::query()->create([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->body->id,
            'governance_position_id' => $this->position->id,
            'instrument_type' => 'resolution',
            'instrument_reference' => 'RES-AUTHORITY-1',
            'instrument_date' => CarbonImmutable::today()->toDateString(),
            'effective_date' => CarbonImmutable::today()->toDateString(),
            'appointment_mode' => 'elected',
            'status' => 'draft',
            'title_en' => 'Initial appointment',
        ]);

        Sanctum::actingAs($this->grantee, ['*']);
        $this->patchJson(route('api.governance.appointments.update', [
            'governanceAppointment' => $appointment->id,
        ]), ['title_en' => 'Updated by delegated authority'])->assertOk();
    }

    public function test_self_delegation_and_unknown_actions_are_rejected(): void
    {
        $this->postJson(route('api.governance.authority-grants.store'), [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->administratorPerson->id,
            'action' => 'governance.membership.manage',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'reason' => 'Self delegation must fail',
        ])->assertUnprocessable()->assertJsonValidationErrors(['grantee_person_id']);

        $this->postJson(route('api.governance.authority-grants.store'), [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->granteePerson->id,
            'action' => 'finance.payment.approve',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'reason' => 'Financial authority is explicitly out of scope',
        ])->assertUnprocessable()->assertJsonValidationErrors(['action']);
    }

    public function test_explicit_seat_rejects_overlapping_primary_occupancy_but_allows_adjacent_periods(): void
    {
        $seats = app(GovernanceSeatService::class);
        $seat = $seats->createSeat($this->administrator, (int) $this->tenant->id, (int) $this->body->id, (int) $this->position->id, 'BOARD-CHAIR');
        $start = CarbonImmutable::today()->toDateString();
        $end = CarbonImmutable::today()->addDays(4)->toDateString();

        $first = $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $this->administratorPerson->id, $start, $end);
        $this->assertSame('active', $first->fresh()->status);

        try {
            $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $this->granteePerson->id, $end, null);
            $this->fail('Inclusive same-day overlap on a primary seat must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('governance_seat_id', $exception->errors());
        }

        $adjacent = $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $this->granteePerson->id, CarbonImmutable::parse($end)->addDay()->toDateString(), null);
        $this->assertSame(CarbonImmutable::parse($end)->addDay()->toDateString(), $adjacent->start_date->toDateString());
    }

    public function test_seat_occupancy_rejects_person_without_explicit_tenant_access(): void
    {
        $seats = app(GovernanceSeatService::class);
        $seat = $seats->createSeat($this->administrator, (int) $this->tenant->id, (int) $this->body->id, (int) $this->position->id, 'TENANT-GUARD');
        PersonTenantAccess::query()
            ->where('person_id', $this->granteePerson->id)
            ->where('tenant_id', $this->tenant->id)
            ->delete();

        try {
            $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $this->granteePerson->id, CarbonImmutable::today()->toDateString());
            $this->fail('A global Person without explicit tenant access must not be assigned to a tenant seat.');
        } catch (\\Illuminate\\Validation\\ValidationException $exception) {
            $this->assertArrayHasKey('person_id', $exception->errors());
        }
    }

    public function test_membership_cannot_be_linked_to_a_person_without_a_canonical_account(): void
    {
        $unlinked = new Person([
            'first_name_en' => 'Unlinked',
            'father_name_en' => 'Person',
        ]);
        $unlinked->status = 'active';
        $unlinked->save();
        PersonTenantAccess::query()->create([
            'person_id' => $unlinked->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->administrator->id,
            'status' => 'active',
        ]);

        $seats = app(GovernanceSeatService::class);
        $seat = $seats->createSeat($this->administrator, (int) $this->tenant->id, (int) $this->body->id, (int) $this->position->id, 'MEMBERSHIP-GUARD');
        $membership = GovernanceMembership::query()->where('user_id', $this->grantee->id)->firstOrFail();

        try {
            $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $unlinked->id, CarbonImmutable::today()->toDateString(), null, (int) $membership->id);
            $this->fail('A membership cannot be attached to a Person without a canonical account link.');
        } catch (\\Illuminate\\Validation\\ValidationException $exception) {
            $this->assertArrayHasKey('governance_membership_id', $exception->errors());
        }
    }

    public function test_acting_assignment_is_bounded_scoped_and_revocable_without_replacing_primary_occupancy(): void
    {
        $seats = app(GovernanceSeatService::class);
        $seat = $seats->createSeat($this->administrator, (int) $this->tenant->id, (int) $this->body->id, (int) $this->position->id, 'BOARD-SECRETARY');
        $start = CarbonImmutable::today()->toDateString();
        $primary = $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $this->administratorPerson->id, $start, null);

        $service = app(GovernanceActingAssignmentService::class);
        $assignment = $service->assign(
            $this->administrator,
            (int) $this->tenant->id,
            (int) $seat->id,
            (int) $primary->id,
            (int) $this->granteePerson->id,
            $start,
            CarbonImmutable::today()->addDays(2)->toDateString(),
            'BOARD-RESOLUTION-2026-001',
            ['governance.membership.manage'],
            'Temporary acting assignment under approved resolution',
        );

        $this->assertSame((int) $primary->id, (int) $assignment->substantive_occupancy_id);
        $this->assertSame(['governance.membership.manage'], $assignment->actions);
        $this->assertTrue($assignment->allowsAction('governance.membership.manage', $start));
        $this->assertFalse($assignment->allowsAction('governance.appointment.manage', $start));
        $this->assertSame((int) $primary->id, (int) $seat->fresh()->effectiveOccupancy($start)?->id);

        $revoked = $service->revoke($this->administrator, (int) $this->tenant->id, (int) $assignment->id, 'Acting period concluded early');
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame((int) $primary->id, (int) $seat->fresh()->effectiveOccupancy($start)?->id);
    }

    public function test_authority_grant_rejects_grantee_without_explicit_tenant_access(): void
    {
        PersonTenantAccess::query()
            ->where('person_id', $this->granteePerson->id)
            ->where('tenant_id', $this->tenant->id)
            ->delete();

        $this->postJson(route('api.governance.authority-grants.store'), [
            'governance_position_id' => (int) $this->position->id,
            'grantee_person_id' => (int) $this->granteePerson->id,
            'action' => 'governance.membership.manage',
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'reason' => 'Tenant access is required',
        ])->assertUnprocessable()->assertJsonValidationErrors(['grantee_person_id']);
    }

    public function test_acting_assignment_rejects_person_without_explicit_tenant_access(): void
    {
        $seats = app(GovernanceSeatService::class);
        $seat = $seats->createSeat($this->administrator, (int) $this->tenant->id, (int) $this->body->id, (int) $this->position->id, 'ACTING-TENANT-GUARD');
        $start = CarbonImmutable::today()->toDateString();
        $primary = $seats->occupy($this->administrator, (int) $this->tenant->id, (int) $seat->id, (int) $this->administratorPerson->id, $start, null);

        PersonTenantAccess::query()
            ->where('person_id', $this->granteePerson->id)
            ->where('tenant_id', $this->tenant->id)
            ->delete();

        try {
            app(GovernanceActingAssignmentService::class)->assign(
                $this->administrator,
                (int) $this->tenant->id,
                (int) $seat->id,
                (int) $primary->id,
                (int) $this->granteePerson->id,
                $start,
                CarbonImmutable::today()->addDays(2)->toDateString(),
                'BOARD-RESOLUTION-TENANT-GUARD',
                ['governance.membership.manage'],
                'Tenant access must be explicit',
            );
            $this->fail('An acting person without explicit tenant access must be rejected.');
        } catch (\\Illuminate\\Validation\\ValidationException $exception) {
            $this->assertArrayHasKey('acting_person_id', $exception->errors());
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

        PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]);

        return $person;
    }
}
