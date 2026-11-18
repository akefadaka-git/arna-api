<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentNumberingSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function entity_codes_are_unique_within_a_tenant_but_scoped_across_tenants(): void
    {
        [$tenantA, $entityA] = $this->entity();
        [, $entityB] = $this->entity($tenantA);
        [$tenantB, $entityC] = $this->entity();

        $this->assignEntityCode($tenantA, $entityA, '01');
        $this->assignEntityCode($tenantB, $entityC, '01');

        $this->expectException(QueryException::class);
        $this->assignEntityCode($tenantA, $entityB, '01');
    }

    #[Test]
    public function department_codes_are_unique_within_the_legal_entity(): void
    {
        [$tenant, $entity] = $this->entity();
        $unitA = $this->unit($tenant, $entity, 'FIN');
        $unitB = $this->unit($tenant, $entity, 'HR');
        $this->assignEntityCode($tenant, $entity, '01');
        $this->assignDepartmentCode($tenant, $entity, $unitA, '03');

        $this->expectException(QueryException::class);
        $this->assignDepartmentCode($tenant, $entity, $unitB, '03');
    }

    #[Test]
    public function counter_scope_is_unique_for_ee_dd_tt_and_full_ec_year(): void
    {
        [$tenant, $entity] = $this->entity();
        $unit = $this->unit($tenant, $entity, 'FIN');
        $this->assignEntityCode($tenant, $entity, '01');
        $this->assignDepartmentCode($tenant, $entity, $unit, '03');

        $counter = [
            'tenant_id' => $tenant,
            'legal_entity_id' => $entity,
            'organization_unit_id' => $unit,
            'entity_code' => '01',
            'department_code' => '03',
            'document_type_number' => '04',
            'ec_year' => 2019,
            'next_sequence' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('document_number_counters')->insert($counter);

        $this->expectException(QueryException::class);
        DB::table('document_number_counters')->insert($counter);
    }

    /** @return array{int,int} */
    private function entity(?int $tenantId = null): array
    {
        $tenant = $tenantId ?? (int) Tenant::factory()->create(['is_active' => true])->id;
        $entityId = DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant,
            'code' => 'LEGAL-'.uniqid(),
            'name_en' => 'Test Legal Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $entityId];
    }

    private function unit(int $tenantId, int $entityId, string $code): int
    {
        return DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'code' => $code.'-'.uniqid(),
            'unit_type' => 'department',
            'name_en' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignEntityCode(int $tenantId, int $entityId, string $code): void
    {
        DB::table('document_entity_code_assignments')->insert([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'entity_code' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignDepartmentCode(int $tenantId, int $entityId, int $unitId, string $code): void
    {
        DB::table('document_department_code_assignments')->insert([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'organization_unit_id' => $unitId,
            'entity_code' => DB::table('document_entity_code_assignments')
                ->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId)->value('entity_code'),
            'department_code' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
