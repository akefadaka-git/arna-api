<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationNumberingRegistryService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationNumberingRegistryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function numbering_registry_service_is_registered_as_a_singleton(): void
    {
        $first = app(OrganizationNumberingRegistryService::class);
        $second = app(OrganizationNumberingRegistryService::class);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function branch_and_department_registration_is_audited_and_retirement_preserves_the_code(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $companyUnit = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id, 'parent_id' => null, 'code' => 'HQ',
            'unit_type' => 'company', 'name_en' => 'Head Office', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchUnit = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id, 'parent_id' => $companyUnit, 'code' => 'BRANCH-1',
            'unit_type' => 'branch', 'name_en' => 'Branch 1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entity = DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'ENTITY-1', 'name_en' => 'Entity 1',
            'base_currency' => 'ETB', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $departmentUnit = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id, 'parent_id' => $branchUnit, 'legal_entity_id' => $entity, 'code' => 'DEPT-1',
            'unit_type' => 'department', 'name_en' => 'Department 1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(OrganizationNumberingRegistryService::class);
        $branchRecord = $service->registerBranch((int) $tenant->id, $branchUnit, '1', 'Register branch one');
        $departmentRecord = $service->registerDepartment((int) $tenant->id, $departmentUnit, (int) $entity, '1', '1', 'Register department one');

        $this->assertDatabaseHas('organization_audit_events', [
            'tenant_id' => $tenant->id, 'subject_type' => 'branch_number_code', 'action' => 'registered',
        ]);
        $this->assertDatabaseHas('organization_audit_events', [
            'tenant_id' => $tenant->id, 'subject_type' => 'department_number_code', 'action' => 'registered',
        ]);

        try {
            $service->retireBranch((int) $tenant->id, (int) $branchRecord->id, 'Cannot retire with active department');
            $this->fail('A branch with an active department code cannot be retired.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertTrue(true);
        }

        $retired = $service->retireDepartment((int) $tenant->id, (int) $departmentRecord->id, 'Department permanently closed');
        $this->assertSame('retired', $retired->status);
        $this->assertNotNull($retired->effective_until);
        $this->assertDatabaseHas('organization_audit_events', [
            'tenant_id' => $tenant->id, 'subject_type' => 'department_number_code', 'action' => 'retired',
        ]);
        try {
            $service->retireDepartment((int) $tenant->id, (int) $departmentRecord->id, 'Attempt duplicate retirement');
            $this->fail('A retired BB code cannot be retired again.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertTrue(true);
        }

        $retiredBranch = $service->retireBranch((int) $tenant->id, (int) $branchRecord->id, 'Branch permanently closed');
        $this->assertSame('retired', $retiredBranch->status);
        $this->assertDatabaseHas('organization_audit_events', [
            'tenant_id' => $tenant->id, 'subject_type' => 'branch_number_code', 'action' => 'retired',
        ]);
    }


    #[Test]
    public function numbering_routes_require_the_dedicated_permission_and_write_one_audit_event_per_transition(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $unauthorized = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $unauthorized->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id, 'parent_id' => null, 'code' => 'HTTP-HQ',
            'unit_type' => 'company', 'name_en' => 'HTTP Head Office', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id, 'parent_id' => $company, 'code' => 'HTTP-BR',
            'unit_type' => 'branch', 'name_en' => 'HTTP Branch', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entity = DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'HTTP-ENTITY', 'name_en' => 'HTTP Entity',
            'base_currency' => 'ETB', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $department = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id, 'parent_id' => $branch, 'legal_entity_id' => $entity,
            'code' => 'HTTP-DEPT', 'unit_type' => 'department', 'name_en' => 'HTTP Department',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($unauthorized);
        $headers = ['X-Tenant-ID' => (string) $tenant->id];
        $this->withHeaders($headers)->postJson('/api/organization/numbering/branches', [
            'organization_unit_id' => $branch, 'branch_code' => '2', 'reason' => 'Register HTTP branch',
        ])->assertForbidden();

        $manager = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $manager->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = Role::query()->firstOrCreate(
            ['slug' => 'organization-numbering-manager-'.$tenant->id],
            ['name_en' => 'Organization Numbering Manager', 'scope' => 'tenant', 'tenant_id' => $tenant->id]
        );
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'organization.numbering.manage'],
            ['name_en' => 'Manage Organization Numbering Registry', 'scope' => 'tenant', 'tenant_id' => $tenant->id, 'category' => 'organization']
        );
        DB::table('role_has_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_id' => $permission->id], []
        );
        DB::table('user_has_roles')->updateOrInsert(
            ['user_id' => $manager->id, 'role_id' => $role->id],
            ['assigned_at' => now(), 'assigned_by' => $manager->id]
        );

        Sanctum::actingAs($manager);
        $branchResponse = $this->withHeaders($headers)->postJson('/api/organization/numbering/branches', [
            'organization_unit_id' => $branch, 'branch_code' => '2', 'reason' => 'Register HTTP branch',
        ])->assertCreated();
        $branchCodeId = (int) $branchResponse->json('data.id');

        $departmentResponse = $this->withHeaders($headers)->postJson('/api/organization/numbering/departments', [
            'organization_unit_id' => $department, 'legal_entity_id' => $entity,
            'branch_code' => '2', 'department_code' => '3', 'reason' => 'Register HTTP department',
        ])->assertCreated();
        $departmentCodeId = (int) $departmentResponse->json('data.id');

        $this->withHeaders($headers)->postJson('/api/organization/numbering/departments/'.$departmentCodeId.'/retire', [
            'reason' => 'Retire HTTP department',
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/organization/numbering/branches/'.$branchCodeId.'/retire', [
            'reason' => 'Retire HTTP branch',
        ])->assertOk();

        $this->assertSame(4, DB::table('organization_audit_events')->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function department_code_cannot_reference_a_legal_entity_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);

        $entityB = DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenantB->id,
            'code' => 'ENTITY-B',
            'name_en' => 'Tenant B entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyA = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantA->id, 'parent_id' => null, 'legal_entity_id' => null,
            'code' => 'TENANT-A-HQ', 'unit_type' => 'company', 'name_en' => 'Tenant A headquarters',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchA = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantA->id, 'parent_id' => $companyA, 'legal_entity_id' => null,
            'code' => 'TENANT-A-BRANCH', 'unit_type' => 'branch', 'name_en' => 'Tenant A branch',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $departmentA = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantA->id, 'parent_id' => $branchA, 'legal_entity_id' => null,
            'code' => 'TENANT-A-DEPT', 'unit_type' => 'department', 'name_en' => 'Tenant A department',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('organization_branch_number_codes')->insert([
            'tenant_id' => $tenantA->id,
            'organization_unit_id' => $branchA,
            'branch_code' => '1',
            'status' => 'active',
            'effective_from' => now()->toDateString(),
            'lifecycle_reason' => 'Initial branch registry entry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('organization_department_number_codes')->insert([
                'tenant_id' => $tenantA->id,
                'branch_code' => '1',
                'department_code' => '1',
                'bb_code' => '11',
                'organization_unit_id' => $departmentA,
                'legal_entity_id' => $entityB,
                'status' => 'active',
                'effective_from' => now()->toDateString(),
                'lifecycle_reason' => 'Reject cross-tenant legal entity reference',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('The database must reject a department code linked to another tenant legal entity.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('organization_department_number_codes', [
                'tenant_id' => $tenantA->id,
                'bb_code' => '11',
            ]);
        }
    }
}
