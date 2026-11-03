<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Services\AnnualDividendDistributionService;
use MaaleArna\MASES\Core\Domains\Finance\Services\GovernancePenaltySettlementService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DividendTaxComplianceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function statutory_dividend_tax_uses_the_amended_fifteen_percent_rate(): void
    {
        [$tenant, $distribution, $shareholderId, $maker, $reviewer, $classId] = $this->fixture();
        $service = $this->service();

        try {
            $service->assessRecipientTax((int) $tenant->id, (int) $distribution->id, $shareholderId, (int) $reviewer->id, [
                'recipient_type' => 'individual', 'tax_residency' => 'resident',
                'treatment' => 'statutory', 'withholding_rate_bps' => 1000,
                'legal_basis' => 'Incorrect superseded statutory rate',
                'effective_from' => '2025-08-07',
            ]);
            $this->fail('The superseded 10 percent rate must be rejected for post-amendment dividend income.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('withholding_rate_bps', $exception->errors());
        }

        $assessment = $service->assessRecipientTax(
            (int) $tenant->id, (int) $distribution->id, $shareholderId, (int) $reviewer->id, [
                'recipient_type' => 'individual', 'tax_residency' => 'resident',
                'treatment' => 'statutory', 'withholding_rate_bps' => 1500,
                'legal_basis' => 'Client supplied label is replaced by statutory basis',
                'effective_from' => '2025-08-07',
            ]
        );

        $this->assertSame(1500, (int) $assessment->withholding_rate_bps);
        $this->assertStringContainsString('Proclamation 1395/2025', $assessment->legal_basis);
        $this->assertStringContainsString('Article 57(1)', $assessment->legal_basis);
    }

    #[Test]
    public function treaty_and_exempt_treatments_require_documentary_evidence(): void
    {
        [$tenant, $distribution, $shareholderId, $maker, $reviewer, $classId] = $this->fixture();
        $companyShareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_number' => 'SH-DIV-TAX-COMPANY-001',
            'shareholder_type' => 'company', 'entity_name_en' => 'Group Company Shareholder',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->addHolding($companyShareholderId, $classId, 100, (int) $maker->id);
        $service = $this->service();

        try {
            $service->assessRecipientTax((int) $tenant->id, (int) $distribution->id, $companyShareholderId, (int) $reviewer->id, [
                'recipient_type' => 'company', 'tax_residency' => 'resident',
                'treatment' => 'exempt', 'withholding_rate_bps' => 0,
                'legal_basis' => 'Income Tax Amendment Article 57(3)',
                'effective_from' => '2025-08-07',
            ]);
            $this->fail('An exemption must not be accepted without evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_reference', $exception->errors());
        }

        $assessment = $service->assessRecipientTax(
            (int) $tenant->id, (int) $distribution->id, $companyShareholderId, (int) $reviewer->id, [
                'recipient_type' => 'company', 'tax_residency' => 'resident',
                'treatment' => 'exempt', 'withholding_rate_bps' => 0,
                'legal_basis' => 'Income Tax Amendment Proclamation 1395/2025, Article 57(3)-(5)',
                'evidence_reference' => 'GROUP-OWNERSHIP-CERTIFICATE-001',
                'effective_from' => '2025-08-07',
            ]
        );

        $this->assertSame('exempt', $assessment->treatment);
        $this->assertSame(0, (int) $assessment->withholding_rate_bps);
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'DIV-TAX',
            'name_en' => 'Dividend Tax Entity', 'base_currency' => 'ETB',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $maker = User::factory()->create();
        $reviewer = User::factory()->create();
        $shareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_number' => 'SH-DIV-TAX-001',
            'shareholder_type' => 'individual', 'first_name_en' => 'Tax Test Shareholder',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $distribution = $this->service()->create((int) $tenant->id, (int) $maker->id, [
            'legal_entity_id' => $entityId,
            'distribution_reference' => 'GA-DIV-TAX-001',
            'financial_year' => '2026',
            'resolution_reference' => 'GA-RES-TAX-001',
            'resolution_date' => today()->subDays(20)->toDateString(),
            'declared_on' => today()->subDays(19)->toDateString(),
            'record_date' => today()->subDays(18)->toDateString(),
            'payment_due_on' => today()->addDays(30)->toDateString(),
            'currency' => 'ETB',
            'declared_amount' => '1000.0000',
            'allocation_method' => 'pro_rata_shares',
            'approved_balance_sheet_reference' => 'APPROVED-BS-2026-001',
            'distributable_profit_amount' => '1500.0000',
            'resolution_evidence_reference' => 'GA-RESOLUTION-001',
            'payment_method' => 'bank_transfer',
            'accounting_framework' => 'ifrs_sme',
        ]);

        $classId = (int) DB::table('share_classes')->insertGetId([
            'code' => 'DIVTAX', 'name_en' => 'Dividend Tax Test Class',
            'par_value' => '10000.0000', 'currency' => 'ETB',
            'is_active' => true, 'is_treasury_eligible' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $weight = $this->service()->requestShareClassWeight(
            (int) $tenant->id, (int) $distribution->id, $classId, (int) $maker->id,
            10000, 'GA-CLASS-TAX-001'
        );
        $this->service()->approveShareClassWeight(
            (int) $tenant->id, (int) $distribution->id, (int) $weight->id, (int) $reviewer->id
        );
        $this->addHolding($shareholderId, $classId, 100, (int) $maker->id);

        return [$tenant, $distribution, $shareholderId, $maker, $reviewer, $classId];
    }

    private function addHolding(int $shareholderId, int $classId, int $quantity, int $actorId): void
    {
        $holdingId = (int) DB::table('share_holdings')->insertGetId([
            'shareholder_id' => $shareholderId, 'share_class_id' => $classId,
            'current_quantity' => number_format($quantity, 4, '.', ''),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('share_holding_entries')->insert([
            'share_holding_id' => $holdingId, 'entry_type' => 'allocation',
            'quantity' => number_format($quantity, 4, '.', ''),
            'balance_before' => '0.0000', 'balance_after' => number_format($quantity, 4, '.', ''),
            'reference_type' => 'DividendTaxComplianceTest', 'reference_id' => (string) $holdingId,
            'description' => 'Shareholding before historical dividend record date',
            'occurred_at' => now()->subDays(19), 'created_by' => $actorId,
            'reversed_entry_id' => null, 'created_at' => now(),
        ]);
    }

    private function service(): AnnualDividendDistributionService
    {
        $journalService = new JournalEntryService(new JournalSourceReferenceResolverRegistry([]));
        return new AnnualDividendDistributionService(new GovernancePenaltySettlementService($journalService));
    }
}
