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

    #[Test]
    public function multi_class_distribution_uses_independently_approved_weights_and_preserves_class_snapshot(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'DIV-WEIGHT-CALC',
            'name_en' => 'Weighted Dividend Entity', 'base_currency' => 'ETB',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $maker = User::factory()->create();
        $reviewer = User::factory()->create();
        $classA = (int) DB::table('share_classes')->insertGetId([
            'code' => 'DIVWEIGHTA', 'name_en' => 'Weighted Class A',
            'par_value' => '10000.0000', 'currency' => 'ETB', 'is_active' => true,
            'is_treasury_eligible' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $classB = (int) DB::table('share_classes')->insertGetId([
            'code' => 'DIVWEIGHTB', 'name_en' => 'Weighted Class B',
            'par_value' => '10000.0000', 'currency' => 'ETB', 'is_active' => true,
            'is_treasury_eligible' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = $this->service();
        $distribution = $service->create((int) $tenant->id, (int) $maker->id, [
            'legal_entity_id' => $entityId, 'distribution_reference' => 'GA-DIV-WEIGHT-001',
            'financial_year' => '2026', 'resolution_reference' => 'GA-RES-WEIGHT-001',
            'resolution_date' => today()->subDays(20)->toDateString(),
            'declared_on' => today()->subDays(19)->toDateString(),
            'record_date' => today()->subDays(5)->toDateString(),
            'payment_due_on' => today()->addDays(30)->toDateString(),
            'currency' => 'ETB', 'declared_amount' => '1000.0000',
            'allocation_method' => 'pro_rata_shares',
            'approved_balance_sheet_reference' => 'BS-WEIGHT-001',
            'distributable_profit_amount' => '1500.0000',
            'resolution_evidence_reference' => 'GA-WEIGHT-001',
            'payment_method' => 'bank_transfer', 'accounting_framework' => 'ifrs_sme',
        ]);

        foreach ([[$classA, 15000], [$classB, 10000]] as [$classId, $weightBps]) {
            $requested = $service->requestShareClassWeight(
                (int) $tenant->id, (int) $distribution->id, $classId,
                (int) $maker->id, $weightBps, 'GA-CLASS-WEIGHT-'.$classId
            );
            $service->approveShareClassWeight(
                (int) $tenant->id, (int) $distribution->id, (int) $requested->id, (int) $reviewer->id
            );
        }

        $shareholderA = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_number' => 'SH-WEIGHT-A',
            'shareholder_type' => 'individual', 'first_name_en' => 'Weighted A',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $shareholderB = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_number' => 'SH-WEIGHT-B',
            'shareholder_type' => 'individual', 'first_name_en' => 'Weighted B',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$shareholderA, $classA], [$shareholderB, $classB]] as [$shareholderId, $classId]) {
            $holdingId = (int) DB::table('share_holdings')->insertGetId([
                'shareholder_id' => $shareholderId, 'share_class_id' => $classId,
                'current_quantity' => '100.0000', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('share_holding_entries')->insert([
                'share_holding_id' => $holdingId, 'entry_type' => 'allocation',
                'quantity' => '100.0000', 'balance_before' => '0.0000', 'balance_after' => '100.0000',
                'reference_type' => 'DividendWeightTest', 'reference_id' => (string) $holdingId,
                'description' => 'Test share allocation',
                'occurred_at' => now()->subDays(6), 'created_by' => $maker->id,
                'reversed_entry_id' => null, 'created_at' => now(),
            ]);
        }

        foreach ([$shareholderA, $shareholderB] as $shareholderId) {
            $service->assessRecipientTax((int) $tenant->id, (int) $distribution->id, $shareholderId, (int) $reviewer->id, [
                'recipient_type' => 'individual', 'tax_residency' => 'resident',
                'treatment' => 'statutory', 'withholding_rate_bps' => 1500,
                'legal_basis' => 'Statutory dividend rate', 'effective_from' => '2025-08-07',
            ]);
        }

        $calculated = $service->snapshotAndCalculate((int) $tenant->id, (int) $distribution->id, (int) $maker->id);
        $items = collect($calculated->items)->keyBy('shareholder_id');
        $this->assertSame('600.0000', (string) $items[$shareholderA]->gross_amount);
        $this->assertSame('400.0000', (string) $items[$shareholderB]->gross_amount);
        $this->assertSame('150.0000', (string) $items[$shareholderA]->weighted_share_quantity);
        $this->assertSame('100.0000', (string) $items[$shareholderB]->weighted_share_quantity);
        $this->assertSame(2, DB::table('finance_dividend_distribution_share_class_snapshots')
            ->where('tenant_id', $tenant->id)->where('distribution_id', $distribution->id)->count());
    }

    private function service(): AnnualDividendDistributionService
    {
        $journals = new JournalEntryService(new JournalSourceReferenceResolverRegistry([]));
        return new AnnualDividendDistributionService(new GovernancePenaltySettlementService($journals));
    }
}
