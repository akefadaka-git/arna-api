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

final class DividendCancellationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cancelled_distribution_requires_independent_actor_and_replacement_keeps_audit_link(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenant->id, 'code' => 'DIV-CANCEL',
            'name_en' => 'Dividend Cancellation Entity', 'base_currency' => 'ETB',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $maker = User::factory()->create();
        $reviewer = User::factory()->create();
        $service = $this->service();

        $draft = $service->create((int) $tenant->id, (int) $maker->id, $this->distributionData($entityId, 'GA-DIV-CANCEL-001'));
        try {
            $service->cancel((int) $tenant->id, (int) $draft->id, (int) $maker->id, 'Maker cannot cancel own distribution');
            $this->fail('Distribution creator must not cancel their own decision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('distribution', $exception->errors());
        }

        $cancelled = $service->cancel((int) $tenant->id, (int) $draft->id, (int) $reviewer->id, 'General Meeting amended its dividend resolution');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame((int) $reviewer->id, (int) $cancelled->cancelled_by);

        $replacementData = $this->distributionData($entityId, 'GA-DIV-CANCEL-002');
        $replacementData['replaces_distribution_id'] = (int) $draft->id;
        $replacementData['replacement_reason'] = 'Replacement distribution under amended General Meeting resolution';
        $replacement = $service->create((int) $tenant->id, (int) $maker->id, $replacementData);

        $this->assertSame((int) $draft->id, (int) $replacement->replaces_distribution_id);
        $this->assertSame($replacementData['replacement_reason'], $replacement->replacement_reason);
    }

    private function distributionData(int $entityId, string $reference): array
    {
        return [
            'legal_entity_id' => $entityId, 'distribution_reference' => $reference,
            'financial_year' => '2026', 'resolution_reference' => 'GA-RES-'.$reference,
            'resolution_date' => today()->subDays(20)->toDateString(),
            'declared_on' => today()->subDays(19)->toDateString(),
            'record_date' => today()->subDays(18)->toDateString(),
            'payment_due_on' => today()->addDays(30)->toDateString(),
            'currency' => 'ETB', 'declared_amount' => '1000.0000',
            'allocation_method' => 'pro_rata_shares',
            'approved_balance_sheet_reference' => 'BS-'.$reference,
            'distributable_profit_amount' => '1500.0000',
            'resolution_evidence_reference' => 'GA-EVIDENCE-'.$reference,
            'payment_method' => 'bank_transfer', 'accounting_framework' => 'ifrs_sme',
        ];
    }

    private function service(): AnnualDividendDistributionService
    {
        $journals = new JournalEntryService(new JournalSourceReferenceResolverRegistry([]));
        return new AnnualDividendDistributionService(new GovernancePenaltySettlementService($journals));
    }
}
