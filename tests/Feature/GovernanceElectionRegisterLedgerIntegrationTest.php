<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionRegisterService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceElectionRegisterLedgerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private int $shareClassId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->shareClassId = (int) DB::table('share_classes')->insertGetId([
            'code' => 'ELECT-STD-' . $this->tenant->id,
            'name_en' => 'Election Standard',
            'par_value' => '1.0000',
            'currency' => 'ETB',
            'is_active' => true,
            'is_treasury_eligible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('governance.election_voting.verified_class_entitlements', [
            'ELECT-STD-' . $this->tenant->id => [
                'verified' => true,
                'eligible' => true,
                'votes_per_share' => '1',
                'evidence_reference' => 'VERIFIED-MOA-CLASS-RIGHT',
            ],
        ]);
    }

    public function test_record_date_register_keeps_historical_entitlement_after_later_transfer_out_reduces_projection_to_zero(): void
    {
        $shareholderId = $this->createShareholder('SH-REG-HIST-' . $this->tenant->id);
        $holdingId = $this->createHolding($shareholderId, '0.0000');

        $this->appendEntry($holdingId, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00');
        $this->appendEntry($holdingId, 'transfer_out', '-10.0000', '10.0000', '0.0000', '2026-02-01 09:00:00');

        $entries = app(GovernanceElectionRegisterService::class)->reconstructAsOf(
            (int) $this->tenant->id,
            '2026-01-31',
            'APPROVED-RECORD-DATE-REGISTER',
        );

        self::assertCount(1, $entries);
        self::assertSame($shareholderId, $entries[0]['shareholder_id']);
        self::assertSame('ELECT-STD-' . $this->tenant->id, $entries[0]['share_class_code']);
        self::assertSame('10.0000', $entries[0]['share_quantity']);
        self::assertSame('10.0000', $entries[0]['voting_weight']);
        self::assertFalse($entries[0]['proxy_authorized']);
    }

    public function test_freeze_recomputes_and_matches_the_locked_ledger_instead_of_trusting_submitted_rows(): void
    {
        $shareholderId = $this->createShareholder('SH-REG-FREEZE-' . $this->tenant->id);
        $holdingId = $this->createHolding($shareholderId, '10.0000');
        $this->appendEntry($holdingId, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00');

        $electionId = (int) DB::table('governance_elections')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'code' => 'REGISTER-FREEZE-' . $this->tenant->id,
            'title' => 'Register freeze test',
            'status' => 'planned',
            'record_date' => '2026-01-31',
            'authority_reference' => 'GA-RESOLUTION-TEST',
            'authority_verified_at' => now(),
            'authority_verified_by' => 1,
            'voting_method' => 'physical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(GovernanceElectionRegisterService::class);
        $entries = $service->reconstructAsOf(
            (int) $this->tenant->id,
            '2026-01-31',
            'APPROVED-RECORD-DATE-REGISTER',
        );

        $forged = $entries;
        $forged[0]['share_quantity'] = '11.0000';
        $forged[0]['voting_weight'] = '11.0000';

        try {
            $service->freeze(
                (int) $this->tenant->id,
                $electionId,
                '2026-01-31',
                'APPROVED-RECORD-DATE-REGISTER',
                'VOTING-RULE-V1',
                1,
                $forged,
            );
            self::fail('A caller-supplied register that differs from the ledger must not be frozen.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('entries', $exception->errors());
            self::assertStringContainsString('does not exactly match', $exception->errors()['entries'][0]);
        }

        $snapshotId = $service->freeze(
            (int) $this->tenant->id,
            $electionId,
            '2026-01-31',
            'APPROVED-RECORD-DATE-REGISTER',
            'VOTING-RULE-V1',
            1,
            $entries,
        );

        $this->assertDatabaseHas('governance_election_register_snapshots', [
            'id' => $snapshotId,
            'tenant_id' => $this->tenant->id,
            'election_id' => $electionId,
            'record_date' => '2026-01-31',
            'voter_count' => 1,
        ]);
        $this->assertDatabaseHas('governance_election_voters', [
            'snapshot_id' => $snapshotId,
            'shareholder_id' => $shareholderId,
            'share_class_code' => 'ELECT-STD-' . $this->tenant->id,
            'share_quantity' => '10.0000',
            'voting_weight' => '10.0000',
            'proxy_authorized' => false,
        ]);
    }

    public function test_nonzero_projection_without_ledger_is_not_silently_omitted_from_a_register_with_other_valid_holders(): void
    {
        $validShareholderId = $this->createShareholder('SH-REG-VALID-' . $this->tenant->id);
        $validHoldingId = $this->createHolding($validShareholderId, '0.0000');
        $this->appendEntry($validHoldingId, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00');
        $this->appendEntry($validHoldingId, 'transfer_out', '-10.0000', '10.0000', '0.0000', '2026-02-01 09:00:00');

        $missingLedgerShareholderId = $this->createShareholder('SH-REG-MISSING-' . $this->tenant->id);
        $this->createHolding($missingLedgerShareholderId, '5.0000');

        try {
            app(GovernanceElectionRegisterService::class)->reconstructAsOf(
                (int) $this->tenant->id,
                '2026-01-31',
                'APPROVED-RECORD-DATE-REGISTER',
            );
            self::fail('A non-zero holding projection without ledger history must block reconstruction.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('share_ledger', $exception->errors());
            self::assertStringContainsString('no immutable ledger history', $exception->errors()['share_ledger'][0]);
        }
    }

    public function test_soft_deleted_shareholder_with_positive_record_date_balance_blocks_register_freeze(): void
    {
        $shareholderId = $this->createShareholder('SH-REG-DELETED-' . $this->tenant->id);
        $holdingId = $this->createHolding($shareholderId, '10.0000');
        $this->appendEntry($holdingId, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00');
        DB::table('shareholders')->where('id', $shareholderId)->update([
            'deleted_at' => '2026-02-01 00:00:00',
        ]);

        try {
            app(GovernanceElectionRegisterService::class)->reconstructAsOf(
                (int) $this->tenant->id,
                '2026-01-31',
                'APPROVED-RECORD-DATE-REGISTER',
            );
            self::fail('A soft-deleted shareholder with a positive record-date balance must be explicitly resolved.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('shareholder_eligibility', $exception->errors());
            self::assertStringContainsString('eligibility must be resolved', $exception->errors()['shareholder_eligibility'][0]);
        }
    }

    public function test_zero_projection_without_any_ledger_history_is_not_counted_as_a_voter(): void
    {
        $shareholderId = $this->createShareholder('SH-REG-ZERO-' . $this->tenant->id);
        $this->createHolding($shareholderId, '0.0000');

        try {
            app(GovernanceElectionRegisterService::class)->reconstructAsOf(
                (int) $this->tenant->id,
                '2026-01-31',
                'APPROVED-RECORD-DATE-REGISTER',
            );
            self::fail('A zero projection with no ledger history cannot create an entitlement.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('entries', $exception->errors());
            self::assertStringContainsString('No eligible positive share entitlements', $exception->errors()['entries'][0]);
        }
    }

    public function test_zero_projection_with_pre_record_date_holding_and_missing_ledger_blocks_reconstruction(): void
    {
        $shareholderId = $this->createShareholder('SH-REG-OLD-EMPTY-' . $this->tenant->id);
        $holdingId = $this->createHolding($shareholderId, '0.0000');
        DB::table('share_holdings')->where('id', $holdingId)->update([
            'created_at' => '2025-12-01 00:00:00',
            'updated_at' => '2025-12-01 00:00:00',
        ]);

        try {
            app(GovernanceElectionRegisterService::class)->reconstructAsOf(
                (int) $this->tenant->id,
                '2026-01-31',
                'APPROVED-RECORD-DATE-REGISTER',
            );
            self::fail('A pre-record-date holding without immutable history must block reconstruction.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('share_ledger', $exception->errors());
            self::assertStringContainsString('record-date balance', $exception->errors()['share_ledger'][0]);
        }
    }

    private function createShareholder(string $number): int
    {
        return (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'shareholder_number' => $number,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Register Test Holder',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createHolding(int $shareholderId, string $projection): int
    {
        return (int) DB::table('share_holdings')->insertGetId([
            'shareholder_id' => $shareholderId,
            'share_class_id' => $this->shareClassId,
            'current_quantity' => $projection,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function appendEntry(
        int $holdingId,
        string $type,
        string $quantity,
        string $before,
        string $after,
        string $occurredAt,
    ): void {
        DB::table('share_holding_entries')->insert([
            'share_holding_id' => $holdingId,
            'entry_type' => $type,
            'quantity' => $quantity,
            'balance_before' => $before,
            'balance_after' => $after,
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ]);
    }
}
