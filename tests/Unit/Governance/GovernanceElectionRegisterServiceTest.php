<?php

declare(strict_types=1);

namespace Tests\Unit\Governance;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionRegisterService;
use Tests\TestCase;

final class GovernanceElectionRegisterServiceTest extends TestCase
{
    public function test_default_entitlement_is_proportional_to_eligible_shares(): void
    {
        self::assertSame('125.0000', GovernanceElectionRegisterService::defaultVotingWeight('125', '1'));
        self::assertSame('250.0000', GovernanceElectionRegisterService::defaultVotingWeight('125', '2'));
    }

    public function test_fractional_share_quantity_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::defaultVotingWeight('10.5', '1');
    }

    public function test_missing_verified_class_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::defaultVotingWeight('10');
    }

    public function test_zero_or_negative_class_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::defaultVotingWeight('10', '0');
    }

    public function test_record_date_balance_handles_a_backdated_entry_without_corrupting_append_order_chain(): void
    {
        $ledger = [
            $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
            $this->entry(2, 'subscription', '10.0000', '10.0000', '20.0000', '2026-02-01 09:00:00'),
            $this->entry(3, 'adjustment', '5.0000', '20.0000', '25.0000', '2026-01-15 09:00:00'),
            $this->entry(4, 'subscription', '5.0000', '25.0000', '30.0000', '2026-03-01 09:00:00'),
        ];

        self::assertSame(
            '15.0000',
            GovernanceElectionRegisterService::reconstructHistoricalHoldingBalance($ledger, '2026-01-31', '30.0000')
        );
    }

    public function test_record_date_balance_applies_reversals_only_from_their_business_date(): void
    {
        $ledger = [
            $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
            $this->entry(2, 'reversal', '-10.0000', '10.0000', '0.0000', '2026-02-01 09:00:00', 1),
        ];

        self::assertSame(
            '10.0000',
            GovernanceElectionRegisterService::reconstructHistoricalHoldingBalance($ledger, '2026-01-31', '0.0000')
        );
        self::assertSame(
            '0.0000',
            GovernanceElectionRegisterService::reconstructHistoricalHoldingBalance($ledger, '2026-02-28', '0.0000')
        );
    }

    public function test_record_date_reconstruction_rejects_projection_drift(): void
    {
        $ledger = [
            $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
        ];

        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::reconstructHistoricalHoldingBalance($ledger, '2026-01-31', '9.0000');
    }

    public function test_record_date_reconstruction_rejects_invalid_reversal_linkage(): void
    {
        $ledger = [
            $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
            $this->entry(2, 'reversal', '-10.0000', '10.0000', '0.0000', '2026-02-01 09:00:00', 999),
        ];

        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::reconstructHistoricalHoldingBalance($ledger, '2026-02-28', '0.0000');
    }

    private function entry(
        int $id,
        string $type,
        string $quantity,
        string $before,
        string $after,
        string $occurredAt,
        ?int $reversedEntryId = null,
    ): \\stdClass {
        return (object) [
            'id' => $id,
            'entry_type' => $type,
            'quantity' => $quantity,
            'balance_before' => $before,
            'balance_after' => $after,
            'occurred_at' => $occurredAt,
            'reversed_entry_id' => $reversedEntryId,
        ];
    }

}
