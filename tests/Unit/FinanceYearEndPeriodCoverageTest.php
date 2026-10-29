<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\MASES\Core\Domains\Finance\Services\YearEndCloseReadinessService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinanceYearEndPeriodCoverageTest extends TestCase
{
    #[Test]
    public function it_accepts_contiguous_period_coverage_for_a_fiscal_year(): void
    {
        $result = YearEndCloseReadinessService::periodCoverage([
            ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'],
            ['starts_on' => '2026-07-01', 'ends_on' => '2026-12-31'],
        ], '2026-01-01', '2026-12-31');

        self::assertTrue($result['covered']);
        self::assertSame([], $result['gaps']);
    }

    #[Test]
    public function it_reports_missing_dates_between_accounting_periods(): void
    {
        $result = YearEndCloseReadinessService::periodCoverage([
            ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'],
            ['starts_on' => '2026-07-02', 'ends_on' => '2026-12-31'],
        ], '2026-01-01', '2026-12-31');

        self::assertFalse($result['covered']);
        self::assertSame([['from' => '2026-07-01', 'to' => '2026-07-01']], $result['gaps']);
    }

    #[Test]
    public function it_fails_closed_when_no_accounting_periods_cover_the_year(): void
    {
        $result = YearEndCloseReadinessService::periodCoverage([], '2026-01-01', '2026-12-31');

        self::assertFalse($result['covered']);
        self::assertSame([['from' => '2026-01-01', 'to' => '2026-12-31']], $result['gaps']);
    }
}
