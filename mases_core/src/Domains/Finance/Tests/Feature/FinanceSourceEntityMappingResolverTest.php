<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalSourceEntityMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceEntityMappingResolver;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceSourceEntityMappingResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_valid_source_record_and_active_mapping_for_requested_entity_are_accepted(): void
    {
        [$tenant, $entity] = $this->tenantAndEntity();
        $sourceId = $this->createShareholder((int) $tenant->id);
        $this->createMapping((int) $tenant->id, (int) $entity->id, $sourceId);

        app(JournalSourceEntityMappingResolver::class)->assertValid(
            JournalSourceType::SHAREHOLDER,
            (string) $sourceId,
            (int) $tenant->id,
            (int) $entity->id,
        );

        $this->assertTrue(true);
    }

    #[Test]
    public function test_missing_source_record_is_rejected_even_if_mapping_row_exists(): void
    {
        [$tenant, $entity] = $this->tenantAndEntity();
        $this->createMapping((int) $tenant->id, (int) $entity->id, 'missing-source-999');

        $this->assertRejected(
            JournalSourceType::SHAREHOLDER,
            'missing-source-999',
            (int) $tenant->id,
            (int) $entity->id,
        );
    }

    #[Test]
    public function test_inactive_source_mapping_is_rejected(): void
    {
        [$tenant, $entity] = $this->tenantAndEntity();
        $sourceId = $this->createShareholder((int) $tenant->id);
        $this->createMapping(
            (int) $tenant->id,
            (int) $entity->id,
            $sourceId,
            JournalSourceEntityMapping::STATUS_INACTIVE,
        );

        $this->assertRejected(
            JournalSourceType::SHAREHOLDER,
            (string) $sourceId,
            (int) $tenant->id,
            (int) $entity->id,
        );
    }

    #[Test]
    public function test_source_record_owned_by_another_tenant_is_rejected(): void
    {
        [$tenant, $entity] = $this->tenantAndEntity();
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $sourceId = $this->createShareholder((int) $otherTenant->id);
        $this->createMapping((int) $tenant->id, (int) $entity->id, $sourceId);

        $this->assertRejected(
            JournalSourceType::SHAREHOLDER,
            (string) $sourceId,
            (int) $tenant->id,
            (int) $entity->id,
        );
    }

    #[Test]
    public function test_mapping_to_a_different_legal_entity_is_rejected(): void
    {
        [$tenant, $entity] = $this->tenantAndEntity();
        $otherEntity = $this->createEntity((int) $tenant->id, 'OTHER');
        $sourceId = $this->createShareholder((int) $tenant->id);
        $this->createMapping((int) $tenant->id, (int) $entity->id, $sourceId);

        $this->assertRejected(
            JournalSourceType::SHAREHOLDER,
            (string) $sourceId,
            (int) $tenant->id,
            (int) $otherEntity->id,
        );
    }

    #[Test]
    public function test_unsupported_source_type_is_rejected_by_resolver(): void
    {
        [$tenant, $entity] = $this->tenantAndEntity();

        $this->assertFalse(
            app(JournalSourceEntityMappingResolver::class)->supports(JournalSourceType::PROCUREMENT)
        );

        $this->assertRejected(
            JournalSourceType::PROCUREMENT,
            'procurement-1',
            (int) $tenant->id,
            (int) $entity->id,
        );
    }

    /**
     * @return array{Tenant, LegalEntity}
     */
    private function tenantAndEntity(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        return [$tenant, $this->createEntity((int) $tenant->id, 'MAIN')];
    }

    private function createEntity(int $tenantId, string $code): LegalEntity
    {
        return LegalEntity::query()->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name_en' => 'Test ' . $code,
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
    }

    private function createShareholder(int $tenantId): int
    {
        return (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenantId,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Resolver Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createMapping(
        int $tenantId,
        int $legalEntityId,
        int|string $sourceId,
        string $status = JournalSourceEntityMapping::STATUS_ACTIVE,
    ): void {
        $mapping = new JournalSourceEntityMapping();
        $mapping->fill([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'source_type' => JournalSourceType::SHAREHOLDER->value,
            'source_id' => (string) $sourceId,
            'created_by' => null,
            'mapping_reason' => 'Automated resolver regression test fixture',
        ]);
        $approver = User::factory()->create();
        $mapping->forceFill([
            'status' => $status,
            'approved_by' => $status === JournalSourceEntityMapping::STATUS_APPROVED ? $approver->id : null,
            'approved_at' => $status === JournalSourceEntityMapping::STATUS_APPROVED ? now() : null,
        ]);
        $mapping->save();
    }

    private function assertRejected(
        JournalSourceType $sourceType,
        string $sourceId,
        int $tenantId,
        int $legalEntityId,
    ): void {
        try {
            app(JournalSourceEntityMappingResolver::class)->assertValid(
                $sourceType,
                $sourceId,
                $tenantId,
                $legalEntityId,
            );
            $this->fail('The resolver should reject this source reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_id', $exception->errors());
        }
    }
}
