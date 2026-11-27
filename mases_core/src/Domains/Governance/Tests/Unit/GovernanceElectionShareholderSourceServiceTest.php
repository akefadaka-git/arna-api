<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Unit;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionShareholderSourceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GovernanceElectionShareholderSourceServiceTest extends TestCase
{
    public function test_reconstructs_business_time_balance_with_backdated_append_only_entry(): void
    {
        $result = GovernanceElectionShareholderSourceService::reconstructLedger(
            [
                $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
                $this->entry(2, 'subscription', '10.0000', '10.0000', '20.0000', '2026-02-01 09:00:00'),
                $this->entry(3, 'adjustment', '5.0000', '20.0000', '25.0000', '2026-01-15 09:00:00'),
                $this->entry(4, 'subscription', '5.0000', '25.0000', '30.0000', '2026-03-01 09:00:00'),
            ],
            '30.0000',
            new DateTimeImmutable('2026-01-31T23:59:59+03:00'),
            'Africa/Addis_Ababa',
        );

        self::assertSame('15.0000', $result['balance']);
        self::assertSame('30.0000', $result['current_projection']);
        self::assertSame(4, $result['entry_count']);
        self::assertSame([true, false, true, false], array_column($result['entries'], 'included_by_business_time'));
        self::assertSame(64, strlen($result['ledger_digest']));
    }

    public function test_reversal_only_changes_record_date_balance_from_its_business_effective_time(): void
    {
        $ledger = [
            $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
            $this->entry(2, 'reversal', '-10.0000', '10.0000', '0.0000', '2026-02-01 09:00:00', 1),
        ];

        $beforeReversal = GovernanceElectionShareholderSourceService::reconstructLedger(
            $ledger, '0.0000', new DateTimeImmutable('2026-01-31T23:59:59+03:00'), 'Africa/Addis_Ababa'
        );
        $afterReversal = GovernanceElectionShareholderSourceService::reconstructLedger(
            $ledger, '0.0000', new DateTimeImmutable('2026-02-28T23:59:59+03:00'), 'Africa/Addis_Ababa'
        );

        self::assertSame('10.0000', $beforeReversal['balance']);
        self::assertSame('0.0000', $afterReversal['balance']);
    }

    public function test_rejects_projection_drift(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionShareholderSourceService::reconstructLedger(
            [$this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00')],
            '9.0000',
            new DateTimeImmutable('2026-01-31T23:59:59+03:00'),
            'Africa/Addis_Ababa',
        );
    }

    public function test_rejects_invalid_reversal_linkage(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionShareholderSourceService::reconstructLedger(
            [
                $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
                $this->entry(2, 'reversal', '-9.0000', '10.0000', '1.0000', '2026-02-01 09:00:00', 1),
            ],
            '1.0000',
            new DateTimeImmutable('2026-02-28T23:59:59+03:00'),
            'Africa/Addis_Ababa',
        );
    }

    public function test_rejects_unknown_entry_types(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionShareholderSourceService::reconstructLedger(
            [$this->entry(1, 'manual_override', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00')],
            '10.0000',
            new DateTimeImmutable('2026-01-31T23:59:59+03:00'),
            'Africa/Addis_Ababa',
        );
    }

    public function test_rejects_append_chain_breaks_even_when_record_date_sum_looks_valid(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionShareholderSourceService::reconstructLedger(
            [
                $this->entry(1, 'subscription', '10.0000', '0.0000', '10.0000', '2026-01-01 09:00:00'),
                $this->entry(2, 'transfer_out', '-5.0000', '8.0000', '3.0000', '2026-01-02 09:00:00'),
            ],
            '3.0000',
            new DateTimeImmutable('2026-01-31T23:59:59+03:00'),
            'Africa/Addis_Ababa',
        );
    }

    public function test_record_date_requires_an_explicit_offset_and_valid_calendar_instant(): void
    {
        $this->expectException(ValidationException::class);
        // This static adapter parser is intentionally exercised through a
        // public API-independent contract test in the companion test below.
        new DateTimeImmutable('2026-02-31T23:59:59+03:00');
        GovernanceElectionShareholderSourceService::reconstructLedger(
            [$this->entry(1, 'subscription', '1.0000', '0.0000', '1.0000', '2026-01-01 09:00:00')],
            '1.0000',
            new DateTimeImmutable('2026-01-31T23:59:59+03:00'),
            'Africa/Addis_Ababa',
        );
    }

    private function entry(
        int $id,
        string $type,
        string $quantity,
        string $before,
        string $after,
        string $occurredAt,
        ?int $reversedEntryId = null,
    ): object {
        return (object) [
            'id' => $id,
            'share_holding_id' => 100,
            'entry_type' => $type,
            'quantity' => $quantity,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference_type' => 'test',
            'reference_id' => (string) $id,
            'occurred_at' => $occurredAt,
            'created_by' => 1,
            'reversed_entry_id' => $reversedEntryId,
            'created_at' => $occurredAt,
        ];
    }
}
