<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\MASES\Core\Domains\Finance\Support\MoneyArithmetic;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceMoneyArithmeticTest extends TestCase
{
    #[Test]
    public function it_adds_large_amounts_without_float_rounding(): void
    {
        $sum = MoneyArithmetic::add(
            MoneyArithmetic::toMinorUnits('9999999999999999.9999'),
            MoneyArithmetic::toMinorUnits('0.0001'),
        );

        $this->assertSame('100000000000000000000', $sum);
        $this->assertSame('10000000000000000.0000', MoneyArithmetic::format($sum));
    }

    #[Test]
    public function it_compares_and_subtracts_decimal_minor_units_exactly(): void
    {
        $debit = MoneyArithmetic::toMinorUnits('125.2500');
        $credit = MoneyArithmetic::toMinorUnits('25.1250');

        $this->assertSame(1, MoneyArithmetic::compare($debit, $credit));
        $this->assertSame('100.1250', MoneyArithmetic::format(MoneyArithmetic::subtract($debit, $credit)));
    }

    #[Test]
    public function it_normalizes_zero_and_pads_fractional_digits(): void
    {
        $this->assertSame('0', MoneyArithmetic::toMinorUnits('0.0000'));
        $this->assertSame('123400', MoneyArithmetic::toMinorUnits('12.34'));
        $this->assertSame('0.0000', MoneyArithmetic::format('0'));
    }
}
