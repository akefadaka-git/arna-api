<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberAllocator;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberFormatter;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class DocumentNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_allocate_outside_the_issuance_transaction(): void
    {
        $allocator = new DocumentNumberAllocator(new DocumentNumberFormatter());

        $this->expectException(LogicException::class);
        $allocator->allocate(1, 1, 1, 'LET', 2019);
    }

    #[Test]
    public function it_allocates_and_increments_the_exact_entity_department_type_and_ec_year_scope(): void
    {
        [$tenantId, $entityId, $unitId] = $this->registeredIssuer('01', '03');
        $allocator = new DocumentNumberAllocator(new DocumentNumberFormatter());

        $first = DB::transaction(fn () => $allocator->allocate($tenantId, $entityId, $unitId, 'INV', 2019));
        $second = DB::transaction(fn () => $allocator->allocate($tenantId, $entityId, $unitId, 'INV', 2019));
        $otherType = DB::transaction(fn () => $allocator->allocate($tenantId, $entityId, $unitId, 'RCT', 2019));
        $otherYear = DB::transaction(fn () => $allocator->allocate($tenantId, $entityId, $unitId, 'INV', 2020));

        $this->assertSame('ማዓ/01/03/04/2019/0001', $first['canonical_number']);
        $this->assertSame('ማዓ/01/03/04/2019/0002', $second['canonical_number']);
        $this->assertSame('ማዓ/01/03/15/2019/0001', $otherType['canonical_number']);
        $this->assertSame('ማዓ/01/03/04/2020/0001', $otherYear['canonical_number']);
        $this->assertSame(2019, $first['ec_year']);
        $this->assertSame('01', $first['entity_code']);
        $this->assertSame('03', $first['department_code']);
    }

    #[Test]
    public function it_uses_a_distinct_sequence_for_a_different_department(): void
    {
        [$tenantId, $entityId, $unitId] = $this->registeredIssuer('01', '03');
        $otherUnitId = $this->registerDepartment($tenantId, $entityId, '04');
        $allocator = new DocumentNumberAllocator(new DocumentNumberFormatter());

        $first = DB::transaction(fn () => $allocator->allocate($tenantId, $entityId, $unitId, 'LET', 2019));
        $other = DB::transaction(fn () => $allocator->allocate($tenantId, $entityId, $otherUnitId, 'LET', 2019));

        $this->assertSame('ማዓ/01/03/01/2019/0001', $first['canonical_number']);
        $this->assertSame('ማዓ/01/04/01/2019/0001', $other['canonical_number']);
    }

    #[Test]
    public function it_fails_closed_when_the_four_digit_sequence_is_exhausted(): void
    {
        [$tenantId, $entityId, $unitId] = $this->registeredIssuer('01', '03');
        DB::table('document_number_counters')->insert([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'organization_unit_id' => $unitId,
            'entity_code' => '01',
            'department_code' => '03',
            'document_type_number' => '01',
            'ec_year' => 2019,
            'next_sequence' => 10000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        DB::transaction(fn () => (new DocumentNumberAllocator(new DocumentNumberFormatter()))
            ->allocate($tenantId, $entityId, $unitId, 'LET', 2019));
    }

    #[Test]
    public function it_rejects_an_issuer_without_approved_entity_and_department_codes(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityId = DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'UNASSIGNED',
            'name_en' => 'Unassigned Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entityId,
            'code' => 'UNASSIGNED-HQ',
            'unit_type' => 'department',
            'name_en' => 'Unassigned Department',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ConflictHttpException::class);
        DB::transaction(fn () => (new DocumentNumberAllocator(new DocumentNumberFormatter()))
            ->allocate((int) $tenant->id, $entityId, $unitId, 'LET', 2019));
    }

    /** @return array{int,int,int} */
    private function registeredIssuer(string $entityCode, string $departmentCode): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityId = DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'ENTITY-' . $entityCode,
            'name_en' => 'Test Legal Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entityId,
            'code' => 'UNIT-' . $departmentCode,
            'unit_type' => 'department',
            'name_en' => 'Test Department',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('document_entity_code_assignments')->insert([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entityId,
            'entity_code' => $entityCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->registerDepartment((int) $tenant->id, $entityId, $departmentCode, $unitId);

        return [(int) $tenant->id, $entityId, $unitId];
    }

    private function registerDepartment(int $tenantId, int $entityId, string $departmentCode, ?int $unitId = null): int
    {
        if ($unitId === null) {
            $unitId = DB::table('organization_units')->insertGetId([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $entityId,
                'code' => 'UNIT-' . $departmentCode,
                'unit_type' => 'department',
                'name_en' => 'Department ' . $departmentCode,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('document_department_code_assignments')->insert([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'organization_unit_id' => $unitId,
            'entity_code' => DB::table('document_entity_code_assignments')
                ->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId)->value('entity_code'),
            'department_code' => $departmentCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }
}
