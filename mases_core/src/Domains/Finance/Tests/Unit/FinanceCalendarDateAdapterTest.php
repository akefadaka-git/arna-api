<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Support\FinanceCalendarDateAdapter;
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
}
