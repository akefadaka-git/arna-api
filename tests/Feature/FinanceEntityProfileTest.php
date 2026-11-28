<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use MaaleArna\\Arna\\Core\\Auth\\Models\\User;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Services\\LegalEntityService;
use MaaleArna\\Arna\\Core\\Tenancy\\Models\\Tenant;
use PHPUnit\\Framework\\Attributes\\Test;
use Tests\\TestCase;

final class FinanceEntityProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function entity_creation_atomically_creates_a_conservative_ethiopian_accounting_profile(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create([
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);

        $entity = app(LegalEntityService::class)->create((int) $tenant->id, [
            'code' => 'MAALE-ARNA',
            'name_am' => 'ማሌ ዓርና',
            'name_en' => 'Maale Arna Share Company',
            'base_currency' => 'ETB',
        ], (int) $actor->id);

        $this->assertDatabaseHas('finance_entity_profiles', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'functional_currency' => 'ETB',
            'reporting_currency' => 'ETB',
            'transaction_currency_mode' => 'base_only',
            'display_calendar' => 'EC',
            'timezone' => 'Africa/Addis_Ababa',
            'fiscal_year_start_month_ec' => null,
            'fiscal_year_start_day_ec' => null,
            'tax_profile_status' => 'unconfigured',
            'accounting_policy_status' => 'owner_review_required',
        ]);

        $this->assertSame('ETB', $entity->profile->functional_currency);
        $this->assertSame('base_only', $entity->profile->transaction_currency_mode);
    }

    #[Test]
    public function profile_is_unique_for_each_tenant_legal_entity_pair(): void
    {
        $this->assertDatabaseHas('finance_entity_profiles', [
            'functional_currency' => 'ETB',
            'reporting_currency' => 'ETB',
            'transaction_currency_mode' => 'base_only',
        ]);
    }
}
