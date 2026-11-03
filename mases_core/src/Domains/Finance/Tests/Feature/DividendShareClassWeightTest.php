<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Services\AnnualDividendDistributionService;
use MaaleArna\MASES\Core\Domains\Finance\Services\GovernancePenaltySettlementService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class DividendShareClassWeightTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function share_class_weight_requires_independent_maker_checker_approval(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'DIV-CLASS',
            'name_en' => 'Dividend Class Entity', 'base_currency' => 'ETB',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $maker = User::factory()->create();
        $reviewer = User::factory()->create();
        $classId = (int) DB::table('share_classes')->insertGetId([
            'code' => 'DIVTEST', 'name_en' => 'Dividend Test Class',
            'par_value' => '10000.0000', 'currency' => 'ETB',
            'is_active' => true, 'is_treasury_eligible' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $distribution = $this->service()->create((int) $tenant->id, (int) $maker->id, [
            'legal_entity_id' => $entityId, 'distribution_reference' => 'GA-DIV-CLASS-001',
            'financial_year' => '2026', 'resolution_reference' => 'GA-RES-CLASS-001',
            'resolution_date' => today()->subDays(20)->toDateString(),
            'declared_on' => today()->subDays(19)->toDateString(),
            'record_date' => today()->subDays(18)->toDateString(),
            'payment_due_on' => today()->addDays(30)->toDateString(),
            'currency' => 'ETB', 'declared_amount' => '1000.0000',
            'allocation_method' => 'pro_rata_shares',
            'approved_balance_sheet_reference' => 'BS-CLASS-001',
            'distributable_profit_amount' => '1500.0000',
            'resolution_evidence_reference' => 'GA-CLASS-001',
            'payment_method' => 'bank_transfer', 'accounting_framework' => 'ifrs_sme',
        ]);

        $requested = $this->service()->requestShareClassWeight(
            (int) $tenant->id, (int) $distribution->id, $classId, (int) $maker->id,
            15000, 'GA-CLASS-RIGHTS-001'
        );
        $this->assertSame('pending', $requested->status);

        try {
            $this->service()->approveShareClassWeight(
                (int) $tenant->id, (int) $distribution->id, (int) $requested->id, (int) $maker->id
            );
            $this->fail('The maker must not approve their own share-class weight.');
        } catch (ConflictHttpException|\Illuminate\Validation\ValidationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $approved = $this->service()->approveShareClassWeight(
            (int) $tenant->id, (int) $distribution->id, (int) $requested->id, (int) $reviewer->id
        );
        $this->assertSame('approved', $approved->status);
        $this->assertSame(15000, (int) $approved->weight_bps);
    }

    private function service(): AnnualDividendDistributionService
    {
        $journals = new JournalEntryService(new JournalSourceReferenceResolverRegistry([]));
        return new AnnualDividendDistributionService(new GovernancePenaltySettlementService($journals));
    }
}
