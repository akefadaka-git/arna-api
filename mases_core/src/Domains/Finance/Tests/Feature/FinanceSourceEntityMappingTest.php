<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalSourceEntityMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\SourceLegalEntityMappingContract;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceSourceEntityMappingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_missing_mapping_fails_closed(): void
    {
        [$tenant] = $this->fixture();
        $this->expectException(ValidationException::class);
        app(SourceLegalEntityMappingContract::class)->resolve(
            (int) $tenant->id,
            JournalSourceType::LOAN,
            'loan-source-100',
        );
    }

    #[Test]
    public function test_mass_assignment_cannot_set_approval_state(): void
    {
        [$tenant, $user, $entity] = $this->fixture();
        $mapping = JournalSourceEntityMapping::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'source_type' => JournalSourceType::WALLET->value,
            'source_id' => 'wallet-entry-200',
            'status' => JournalSourceEntityMapping::STATUS_APPROVED,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'mapping_reason' => 'Attempted mass-assignment approval',
        ]);

        $this->assertSame(JournalSourceEntityMapping::STATUS_PENDING, $mapping->fresh()->status);
        $this->assertNull($mapping->fresh()->approved_by);
        $this->expectException(ValidationException::class);
        app(SourceLegalEntityMappingContract::class)->resolve(
            (int) $tenant->id,
            JournalSourceType::WALLET,
            'wallet-entry-200',
        );
    }

    #[Test]
    public function test_mapping_from_another_tenant_cannot_be_resolved(): void
    {
        [$tenant, $user, $entity] = $this->fixture();
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        JournalSourceEntityMapping::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'source_type' => JournalSourceType::SHAREHOLDER->value,
            'source_id' => 'shareholding-entry-300',
            'created_by' => $user->id,
            'mapping_reason' => 'Test request',
        ]);

        $this->expectException(ValidationException::class);
        app(SourceLegalEntityMappingContract::class)->resolve(
            (int) $otherTenant->id,
            JournalSourceType::SHAREHOLDER,
            'shareholding-entry-300',
        );
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'MAP-MAIN',
            'name_en' => 'Mapping Test Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);

        return [$tenant, $user, $entity];
    }
}
