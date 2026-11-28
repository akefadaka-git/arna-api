<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceDimensionService;
use MaaleArna\Arna\Core\Domains\Finance\Services\LegalEntityService;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceDimensionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_entity_scoped_dimensions_and_audits_the_creation(): void
    {
        [$tenant, $actor, $entity] = $this->entityFixture();

        $dimension = app(FinanceDimensionService::class)->createDimension(
            (int) $tenant->id,
            (int) $entity->id,
            [
                'code' => 'DEPT',
                'dimension_type' => 'department',
                'name_en' => 'Department',
                'name_am' => 'ክፍል',
            ],
            (int) $actor->id,
        );

        $value = app(FinanceDimensionService::class)->createValue(
            (int) $tenant->id,
            (int) $entity->id,
            (int) $dimension->id,
            ['code' => 'FIN', 'name_en' => 'Finance'],
            (int) $actor->id,
        );

        $this->assertDatabaseHas('finance_dimensions', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => 'DEPT',
            'dimension_type' => 'department',
        ]);
        $this->assertDatabaseHas('finance_dimension_values', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'dimension_id' => $dimension->id,
            'code' => 'FIN',
        ]);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'event_type' => 'finance.dimension.created',
            'subject_id' => $dimension->id,
        ]);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'event_type' => 'finance.dimension_value.created',
            'subject_id' => $value->id,
        ]);
    }

    #[Test]
    public function it_rejects_unsupported_dimension_types(): void
    {
        [$tenant, $actor, $entity] = $this->entityFixture();

        $this->expectException(ValidationException::class);
        app(FinanceDimensionService::class)->createDimension(
            (int) $tenant->id,
            (int) $entity->id,
            ['code' => 'UNKNOWN', 'dimension_type' => 'unregistered', 'name_en' => 'Unknown'],
            (int) $actor->id,
        );
    }

    #[Test]
    public function dimension_values_cannot_use_a_parent_from_another_dimension(): void
    {
        [$tenant, $actor, $entity] = $this->entityFixture();
        $service = app(FinanceDimensionService::class);
        $department = $service->createDimension((int) $tenant->id, (int) $entity->id, [
            'code' => 'DEPT', 'dimension_type' => 'department', 'name_en' => 'Department',
        ], (int) $actor->id);
        $project = $service->createDimension((int) $tenant->id, (int) $entity->id, [
            'code' => 'PROJECT', 'dimension_type' => 'project', 'name_en' => 'Project',
        ], (int) $actor->id);
        $parent = $service->createValue((int) $tenant->id, (int) $entity->id, (int) $department->id, [
            'code' => 'FIN', 'name_en' => 'Finance',
        ], (int) $actor->id);

        $this->expectException(ValidationException::class);
        $service->createValue((int) $tenant->id, (int) $entity->id, (int) $project->id, [
            'code' => 'PROJECT-1', 'name_en' => 'Project One', 'parent_id' => $parent->id,
        ], (int) $actor->id);
    }

    private function entityFixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->id]);
        $entity = app(LegalEntityService::class)->create((int) $tenant->id, [
            'code' => 'DIMENSION-ENTITY',
            'name_en' => 'Dimension Test Entity',
            'base_currency' => 'ETB',
        ], (int) $actor->id);

        return [$tenant, $actor, $entity];
    }
}
