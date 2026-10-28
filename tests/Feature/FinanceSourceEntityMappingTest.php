<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Models\SourceLegalEntityMapping;
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
        [$tenant, $user, $entity] = $this->fixture();

        try {
            app(SourceLegalEntityMappingContract::class)->resolve(
                (int) $tenant->id,
                JournalSourceType::LOAN,
                'loan-source-100',
            );
            $this->fail('A source without an approved mapping must not resolve.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legal_entity_id', $exception->errors());
        }
    }

    #[Test]
    public function test_only_an_explicitly_approved_mapping_resolves(): void
    {
        [$tenant, $user, $entity] = $this->fixture();
        SourceLegalEntityMapping::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'source_type' => JournalSourceType::WALLET->value,
            'source_id' => 'wallet-entry-200',
            'status' => SourceLegalEntityMapping::APPROVED,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'reason' => 'Explicit test approval',
        ]);

        $resolved = app(SourceLegalEntityMappingContract::class)->resolve(
            (int) $tenant->id,
            JournalSourceType::WALLET,
            'wallet-entry-200',
        );

        $this->assertSame((int) $entity->id, $resolved);
    }

    #[Test]
    public function test_mapping_from_another_tenant_cannot_be_resolved(): void
    {
        [$tenant, $user, $entity] = $this->fixture();
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        SourceLegalEntityMapping::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'source_type' => JournalSourceType::SHAREHOLDER->value,
            'source_id' => 'shareholding-entry-300',
            'status' => SourceLegalEntityMapping::APPROVED,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'reason' => 'Explicit test approval',
        ]);

        try {
            app(SourceLegalEntityMappingContract::class)->resolve(
                (int) $otherTenant->id,
                JournalSourceType::SHAREHOLDER,
                'shareholding-entry-300',
            );
            $this->fail('A mapping must not cross tenant boundaries.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('legal_entity_id', $exception->errors());
        }
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
