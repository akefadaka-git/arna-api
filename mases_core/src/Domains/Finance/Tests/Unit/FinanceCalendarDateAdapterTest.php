<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Support\FinanceCalendarDateAdapter;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Http\Resources\TrialBalanceResource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceCalendarDateAdapterTest extends TestCase
{
    #[Test]
    public function it_converts_ethiopian_input_to_gregorian_ledger_date_and_back(): void
    {
        $adapter = new FinanceCalendarDateAdapter();

        $this->assertSame('2023-09-12', $adapter->toGregorianDate('2016-01-01'));
        $this->assertSame('2016-01-01', $adapter->toEthiopianDate('2023-09-12'));
    }

    #[Test]
    public function it_round_trips_the_sixth_pagume_day_in_a_leap_year(): void
    {
        $adapter = new FinanceCalendarDateAdapter();
        $gregorian = $adapter->toGregorianDate('2015-13-06');

        $this->assertSame('2015-13-06', $adapter->toEthiopianDate($gregorian));
    }

    #[Test]
    public function it_rejects_invalid_ethiopian_and_gregorian_dates(): void
    {
        $adapter = new FinanceCalendarDateAdapter();

        $this->expectException(InvalidArgumentException::class);
        $adapter->toGregorianDate('2016-13-06');
    }

    #[Test]
    public function it_rejects_malformed_gregorian_date_strings(): void
    {
        $adapter = new FinanceCalendarDateAdapter();

        $this->expectException(InvalidArgumentException::class);
        $adapter->toEthiopianDate('2026-02-30');
    }
    #[Test]
    public function it_exposes_ethiopian_dates_in_period_journal_and_trial_balance_payloads(): void
    {
        $period = new AccountingPeriod([
            'starts_on' => '2023-09-12',
            'ends_on' => '2023-09-30',
        ]);
        $journal = new JournalEntry(['effective_date' => '2023-09-12']);
        $trialBalance = new TrialBalanceResource([
            'as_of' => '2023-09-12',
            'currency' => 'ETB',
            'accounts' => [],
            'summary' => [],
        ]);

        $this->assertSame('2016-01-01', $period->toArray()['starts_on_ec']);
        $this->assertSame('2016-01-19', $period->toArray()['ends_on_ec']);
        $this->assertSame('2016-01-01', $journal->toArray()['effective_date_ec']);
        $this->assertSame('2016-01-01', $trialBalance->resolve(request())['as_of_ec']);
    }

}
