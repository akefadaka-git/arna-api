<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Executes the opt-in CoA seeder against a real migrated database connection.
 *
 * This verifies the complete orchestration boundary: multi-entity preflight,
 * atomic writes, system-account ownership and repeat-run idempotency.
 */
final class FinanceChartOfAccountsSeederIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_every_active_entity_and_is_idempotent_without_opening_balances(): void
    {
        $entities = [
            $this->activeEntity('COA-INTEGRATION-A'),
            $this->activeEntity('COA-INTEGRATION-B'),
        ];

        $seeder = app(FinanceChartOfAccountsSeeder::class);
        $seeder->run();

        $firstRunIds = [];
        foreach ($entities as $entity) {
            $accounts = DB::table('ledger_accounts')
                ->where('tenant_id', $entity->tenant_id)
                ->where('legal_entity_id', $entity->id)
                ->orderBy('code')
                ->get();

            $this->assertCount(79, $accounts);
            $this->assertSame(79, $accounts->where('is_system_account', true)->count());
            $this->assertSame('1.0.0', $accounts->first()->coa_version);
            $this->assertSame(
                0,
                DB::table('journal_entries')
                    ->where('tenant_id', $entity->tenant_id)
                    ->where('legal_entity_id', $entity->id)
                    ->count(),
                'The canonical CoA seeder must not create opening balances or journals.',
            );

            $firstRunIds[(string) $entity->id] = DB::table('ledger_accounts')
                ->where('tenant_id', $entity->tenant_id)
                ->where('legal_entity_id', $entity->id)
                ->orderBy('code')
                ->pluck('id', 'code')
                ->all();
        }

        $seeder->run();

        foreach ($entities as $entity) {
            $secondRunIds = DB::table('ledger_accounts')
                ->where('tenant_id', $entity->tenant_id)
                ->where('legal_entity_id', $entity->id)
                ->orderBy('code')
                ->pluck('id', 'code')
                ->all();

            $this->assertSame(
                $firstRunIds[(string) $entity->id],
                $secondRunIds,
                'A repeat seed must preserve canonical account identity and must not create duplicates.',
            );
        }

        $this->assertSame(158, DB::table('ledger_accounts')->where('is_system_account', true)->count());
    }

    #[Test]
    public function a_conflict_in_a_later_entity_prevents_all_canonical_writes(): void
    {
        $firstEntity = $this->activeEntity('COA-PREFLIGHT-A');
        $secondEntity = $this->activeEntity('COA-PREFLIGHT-B');
        $register = config('finance.chart_of_accounts_v1', []);
        $canonical = $register[0];

        DB::table('ledger_accounts')->insert([
            'tenant_id' => $secondEntity->tenant_id,
            'legal_entity_id' => $secondEntity->id,
            'parent_id' => null,
            'code' => $canonical[0],
            'name_am' => 'የተከራይ ሂሳብ',
            'name_en' => 'Tenant-owned conflicting account',
            'account_type' => $canonical[4],
            'normal_balance' => $canonical[5],
            'is_posting_allowed' => (bool) $canonical[6],
            'status' => 'active',
            'is_control_account' => false,
            'control_domain' => null,
            'default_tax_category' => 'undetermined',
            'coa_version' => null,
            'is_system_account' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(FinanceChartOfAccountsSeeder::class)->run();
            $this->fail('The seeder must reject a tenant-owned canonical-code collision before writing any entity.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('tenant-owned non-system account', $exception->getMessage());
        }

        $this->assertSame(
            0,
            DB::table('ledger_accounts')
                ->where('tenant_id', $firstEntity->tenant_id)
                ->where('legal_entity_id', $firstEntity->id)
                ->count(),
            'A later-entity conflict must be discovered before the first entity receives canonical rows.',
        );
        $this->assertSame(
            1,
            DB::table('ledger_accounts')
                ->where('tenant_id', $secondEntity->tenant_id)
                ->where('legal_entity_id', $secondEntity->id)
                ->count(),
            'The pre-existing tenant-owned account must remain untouched.',
        );
    }

    private function activeEntity(string $code): LegalEntity
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        return LegalEntity::query()->create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name_en' => $code,
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
    }
}
