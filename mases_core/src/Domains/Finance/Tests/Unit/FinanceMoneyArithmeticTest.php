<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

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

    #[Test]
    public function it_exposes_a_stable_trial_balance_report_shape(): void
    {
        $resource = new \MaaleArna\MASES\Core\Domains\Finance\Http\Resources\TrialBalanceResource([
            'as_of' => '2026-10-10',
            'currency' => 'ETB',
            'accounts' => collect([(object) [
                'id' => 7, 'code' => '1000', 'name_en' => 'Cash', 'name_am' => null,
                'account_type' => 'asset', 'debit_total' => '10.0000', 'credit_total' => '0.0000',
                'net_debit' => '10.0000', 'net_credit' => '0.0000',
            ]]),
            'summary' => ['total_debits' => '10.0000', 'total_credits' => '10.0000',
                'difference' => '0.0000', 'is_balanced' => true],
        ]);

        $data = $resource->resolve(request());

        $this->assertSame('2026-10-10', $data['as_of']);
        $this->assertSame('ETB', $data['currency']);
        $this->assertSame('10.0000', $data['accounts'][0]['net_debit']);
        $this->assertTrue($data['summary']['is_balanced']);
    }

}
