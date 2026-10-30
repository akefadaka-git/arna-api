<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $unitA = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantA->id,
            'parent_id' => null,
            'legal_entity_id' => null,
            'code' => 'TENANT-A-HQ',
            'unit_type' => 'company',
            'name_en' => 'Tenant A headquarters',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_branch_number_codes')->insert([
            'tenant_id' => $tenantA->id,
            'organization_unit_id' => $unitA,
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
                'organization_unit_id' => $unitA,
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
