<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAuditEvent;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationHierarchyPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationAssignmentService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationDomainTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unit_creation_is_tenant_scoped_and_audited(): void
    {
        [$tenant, $user] = $this->manager();
        Sanctum::actingAs($user);
        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)->postJson('/api/organization/units', [
            'code' => 'hq', 'unit_type' => 'company', 'name_am' => 'ዋና መሥሪያ ቤት',
            'reason' => 'Initial organizational registration',
        ]);
        $response->assertCreated()->assertJsonPath('data.code', 'HQ');
        $this->assertDatabaseHas('organization_units', ['tenant_id' => $tenant->id, 'code' => 'HQ']);
        $this->assertDatabaseHas('organization_audit_events', ['tenant_id' => $tenant->id, 'action' => 'created', 'subject_type' => 'unit']);
    }

    #[Test]
    public function parent_from_another_tenant_is_not_resolvable(): void
    {
        [$tenant, $user] = $this->manager();
        $other = Tenant::factory()->create(['is_active' => true]);
        $parentId = DB::table('organization_units')->insertGetId([
            'tenant_id' => $other->id, 'parent_id' => null, 'code' => 'OTHER', 'unit_type' => 'company',
            'name_en' => 'Other', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)->postJson('/api/organization/units', [
            'parent_id' => $parentId, 'code' => 'DIV', 'unit_type' => 'division',
            'name_en' => 'Division', 'reason' => 'Create division under parent',
        ])->assertNotFound();
    }

    #[Test]
    public function missing_permission_denies_unit_creation(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)->postJson('/api/organization/units', [
            'code' => 'HQ', 'unit_type' => 'company', 'name_en' => 'HQ',
            'reason' => 'Initial organizational registration',
        ])->assertForbidden();
    }

    #[Test]
    public function audit_events_reject_model_updates_and_deletes(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $event = OrganizationAuditEvent::query()->create([
            'tenant_id' => $tenant->id,
            'subject_type' => 'unit',
            'subject_id' => 999,
            'action' => 'created',
            'reason' => 'Test append-only audit record',
            'before_state' => null,
            'after_state' => ['status' => 'active'],
            'request_id' => 'audit-immutability-test',
            'created_at' => now(),
        ]);

        try {
            $event->reason = 'tampered';
            $event->save();
            $this->fail('Updating an audit event should throw.');
        } catch (\LogicException $exception) {
            $this->assertSame('Organization audit events are append-only.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Deleting an audit event should throw.');
        } catch (\LogicException $exception) {
            $this->assertSame('Organization audit events are append-only.', $exception->getMessage());
        }

        if (DB::getDriverName() === 'mysql') {
            try {
                DB::table('organization_audit_events')->where('id', $event->id)->update(['reason' => 'bulk tamper']);
                $this->fail('A bulk update must be blocked by the database trigger.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertDatabaseHas('organization_audit_events', ['id' => $event->id, 'reason' => 'Test append-only audit record']);
            }
            try {
                DB::table('organization_audit_events')->where('id', $event->id)->delete();
                $this->fail('A bulk delete must be blocked by the database trigger.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertDatabaseHas('organization_audit_events', ['id' => $event->id]);
            }
        }
    }

    #[Test]
    public function incompatible_parent_type_is_rejected(): void
    {
        [$tenant, $user] = $this->manager();
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-ID' => (string) $tenant->id];

        $company = $this->withHeaders($headers)->postJson('/api/organization/units', [
            'code' => 'HQ', 'unit_type' => 'company', 'name_en' => 'Head Office',
            'reason' => 'Create root company',
        ])->assertCreated()->json('data.id');

        $this->withHeaders($headers)->postJson('/api/organization/units', [
            'parent_id' => $company, 'code' => 'TEAM', 'unit_type' => 'team',
            'name_en' => 'Invalid Team', 'reason' => 'Reject invalid hierarchy edge',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('organization_units', [
            'tenant_id' => $tenant->id, 'code' => 'TEAM',
        ]);
    }

    #[Test]
    public function reparenting_a_root_under_its_descendant_is_rejected(): void
    {
        [$tenant, $user] = $this->manager();
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-ID' => (string) $tenant->id];

        $company = $this->withHeaders($headers)->postJson('/api/organization/units', [
            'code' => 'HQ', 'unit_type' => 'company', 'name_en' => 'Head Office',
            'reason' => 'Create root company',
        ])->assertCreated()->json('data.id');

        $division = $this->withHeaders($headers)->postJson('/api/organization/units', [
            'parent_id' => $company, 'code' => 'DIV', 'unit_type' => 'division',
            'name_en' => 'Division', 'reason' => 'Create division under company',
        ])->assertCreated()->json('data.id');

        $branch = $this->withHeaders($headers)->postJson('/api/organization/units', [
            'parent_id' => $division, 'code' => 'BR', 'unit_type' => 'branch',
            'name_en' => 'Branch', 'reason' => 'Create branch under division',
        ])->assertCreated()->json('data.id');

        $this->withHeaders($headers)->putJson('/api/organization/units/'.$company, [
            'parent_id' => $branch, 'reason' => 'Reject cycle in hierarchy',
        ])->assertStatus(409);

        $this->assertDatabaseHas('organization_units', [
            'id' => $company, 'tenant_id' => $tenant->id, 'parent_id' => null,
        ]);
    }

    #[Test]
    public function assignment_starting_at_today_midnight_is_active_not_scheduled(): void
    {
        [$tenant, $user] = $this->manager();
        Sanctum::actingAs($user);
        $headers = ['X-Tenant-ID' => (string) $tenant->id];

        $unitId = $this->withHeaders($headers)->postJson('/api/organization/units', [
            'code' => 'HQ', 'unit_type' => 'company', 'name_en' => 'Head Office',
            'reason' => 'Create root company',
        ])->assertCreated()->json('data.id');

        $person = Person::query()->create([
            'first_name_en' => 'Test', 'father_name_en' => 'Person',
            'grandfather_name_en' => 'Regression',
        ]);
        PersonTenantAccess::query()->create([
            'person_id' => $person->id, 'tenant_id' => $tenant->id,
            'status' => 'active', 'created_by' => $user->id,
        ]);

        $todayAtMidnight = now()->startOfDay()->toIso8601String();
        $response = $this->withHeaders($headers)->postJson('/api/organization/units/'.$unitId.'/assignments', [
            'person_id' => $person->id, 'assignment_type' => 'primary',
            'starts_on' => $todayAtMidnight,
            'reason' => 'Verify calendar date boundary',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('organization_assignments', [
            'tenant_id' => $tenant->id, 'person_id' => $person->id, 'status' => 'active',
        ]);
    }


    #[Test]
    public function assignment_listing_requires_assignment_view_permission_not_only_unit_view(): void
    {
        [$tenant, $manager] = $this->manager();
        Sanctum::actingAs($manager);
        $headers = ['X-Tenant-ID' => (string) $tenant->id];
        $unitId = $this->withHeaders($headers)->postJson('/api/organization/units', [
            'code' => 'HQ', 'unit_type' => 'company', 'name_en' => 'Head Office',
            'reason' => 'Create root company',
        ])->assertCreated()->json('data.id');

        $reader = $this->memberWithPermissions($tenant, ['organization.unit.view']);
        Sanctum::actingAs($reader);
        $this->withHeaders($headers)->getJson('/api/organization/units/'.$unitId.'/assignments')
            ->assertForbidden();
    }

    #[Test]
    public function tenant_cannot_enumerate_read_or_mutate_another_tenants_units_or_assignments(): void
    {
        [$tenantA, $userA] = $this->manager();
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        Sanctum::actingAs($userA);
        $unitA = $this->withHeader('X-Tenant-ID', (string) $tenantA->id)->postJson('/api/organization/units', [
            'code' => 'A-HQ', 'unit_type' => 'company', 'name_en' => 'Tenant A HQ',
            'reason' => 'Create tenant A root',
        ])->assertCreated()->json('data.id');

        $unitB = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantB->id, 'parent_id' => null, 'code' => 'B-HQ',
            'unit_type' => 'company', 'name_en' => 'Tenant B HQ', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $headersA = ['X-Tenant-ID' => (string) $tenantA->id];
        $this->withHeaders($headersA)->getJson('/api/organization/units')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $unitA);
        $this->withHeaders($headersA)->getJson('/api/organization/units/'.$unitB)->assertNotFound();
        $this->withHeaders($headersA)->putJson('/api/organization/units/'.$unitB, [
            'name_en' => 'Tampered', 'reason' => 'Attempt cross tenant update',
        ])->assertNotFound();
        $this->withHeaders($headersA)->getJson('/api/organization/units/'.$unitB.'/assignments')->assertNotFound();
    }

    #[Test]
    public function inactive_tenant_membership_cannot_access_organization_endpoints(): void
    {
        [$tenant, $user] = $this->manager();
        DB::table('tenant_memberships')->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->update(['status' => 'inactive']);
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/organization/units')->assertForbidden();
    }


    #[Test]
    public function organization_endpoints_fail_closed_without_resolved_tenant_context(): void
    {
        [, $user] = $this->manager();
        Sanctum::actingAs($user);

        $this->getJson('/api/organization/units')
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }

    #[Test]
    public function revoked_tenant_membership_cannot_access_organization_endpoints(): void
    {
        [$tenant, $user] = $this->manager();
        DB::table('tenant_memberships')->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->delete();
        Sanctum::actingAs($user);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/organization/units')->assertForbidden();
    }

    #[Test]
    public function centralized_hierarchy_policy_enforces_the_complete_parent_type_matrix(): void
    {
        $policy = app(OrganizationHierarchyPolicy::class);
        $permitted = [
            'company' => [],
            'division' => ['company'],
            'branch' => ['company', 'division'],
            'department' => ['company', 'division', 'branch'],
            'team' => ['department'],
        ];

        $policy->assertParentType('company', null);
        foreach ($permitted as $childType => $allowedParents) {
            foreach (OrganizationHierarchyPolicy::unitTypes() as $parentType) {
                $parent = new OrganizationUnit(['unit_type' => $parentType]);
                if (in_array($parentType, $allowedParents, true)) {
                    $policy->assertParentType($childType, $parent);
                    continue;
                }

                try {
                    $policy->assertParentType($childType, $parent);
                    $this->fail("Expected {$parentType} -> {$childType} to be rejected.");
                } catch (ConflictHttpException) {
                    $this->assertTrue(true);
                }
            }
            if ($childType !== 'company') {
                try {
                    $policy->assertParentType($childType, null);
                    $this->fail("Expected {$childType} without a parent to be rejected.");
                } catch (ConflictHttpException) {
                    $this->assertTrue(true);
                }
            }
        }

        try {
            $policy->assertParentType('untrusted-custom-type', null);
            $this->fail('Unregistered unit types must be rejected.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
    }



    #[Test]
    public function database_rejects_assignment_when_person_has_no_access_record_for_that_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        $person = Person::query()->create(['first_name_en' => 'Cross', 'father_name_en' => 'Tenant']);
        PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $tenantB->id,
            'status' => 'active',
        ]);
        $unitId = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantA->id,
            'parent_id' => null,
            'code' => 'TENANT-A',
            'unit_type' => 'company',
            'name_en' => 'Tenant A',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('organization_assignments')->insert([
                'tenant_id' => $tenantA->id,
                'organization_unit_id' => $unitId,
                'person_id' => $person->id,
                'assignment_type' => 'primary',
                'starts_on' => now()->toDateString(),
                'status' => 'active',
                'lifecycle_reason' => 'Cross-tenant integrity regression',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Database must reject an assignment without a matching person/tenant access pair.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('organization_assignments', [
                'tenant_id' => $tenantA->id,
                'person_id' => $person->id,
            ]);
        }
    }

    #[Test]
    public function revoked_people_access_preserves_assignment_history_without_exposing_person_profile(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $person = Person::query()->create(['first_name_en' => 'Historical', 'father_name_en' => 'Person']);
        $access = PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        $unit = OrganizationUnit::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'HISTORY',
            'unit_type' => 'company',
            'name_en' => 'History Unit',
            'status' => 'active',
        ]);
        $assignment = OrganizationAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'organization_unit_id' => $unit->id,
            'person_id' => $person->id,
            'assignment_type' => 'primary',
            'starts_on' => now()->toDateString(),
            'status' => 'active',
            'lifecycle_reason' => 'Historical assignment fixture',
        ]);

        $access->update(['status' => 'revoked']);
        $page = app(OrganizationAssignmentService::class)->paginateForUnit((int) $tenant->id, (int) $unit->id, []);

        $this->assertSame(1, $page->total());
        $this->assertSame($assignment->id, $page->items()[0]->id);
        $this->assertNull($page->items()[0]->person);
    }

    private function memberWithPermissions(Tenant $tenant, array $permissionSlugs): User
    {
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = Role::query()->firstOrCreate(
            ['slug' => 'organization-test-reader-'.$tenant->id],
            ['name_en' => 'Organization Test Reader', 'scope' => 'tenant', 'tenant_id' => $tenant->id]
        );
        foreach ($permissionSlugs as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name_en' => $slug, 'scope' => 'tenant', 'tenant_id' => $tenant->id, 'category' => 'organization']
            );
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $role->id, 'permission_id' => $permission->id,
            ], []);
        }
        DB::table('user_has_roles')->updateOrInsert(
            ['user_id' => $user->id, 'role_id' => $role->id],
            ['assigned_at' => now(), 'assigned_by' => $user->id]
        );

        return $user;
    }

    private function manager(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = Role::query()->firstOrCreate(['slug' => 'organization-test-manager-'.$tenant->id], ['name_en' => 'Organization Test Manager', 'scope' => 'tenant', 'tenant_id' => $tenant->id]);
        foreach (['organization.unit.view', 'organization.unit.manage', 'organization.numbering.manage', 'organization.assignment.view', 'organization.assignment.manage'] as $slug) {
            $permission = Permission::query()->firstOrCreate(['slug' => $slug], ['name_en' => $slug, 'scope' => 'tenant', 'tenant_id' => $tenant->id, 'category' => 'organization']);
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $role->id, 'permission_id' => $permission->id,
            ], []);
        }
        DB::table('user_has_roles')->updateOrInsert(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_at' => now(), 'assigned_by' => $user->id]);
        return [$tenant, $user];
    }
}