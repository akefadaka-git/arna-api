<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceExchangeRate;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinanceCurrencyConverter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceCurrencyConverterTest extends TestCase
{
    #[Test]
    public function it_converts_using_an_approved_rate_and_quote_currency_precision(): void
    {
        $rate = new FinanceExchangeRate([
            'base_currency' => 'ETB',
            'quote_currency' => 'KWD',
            'rate' => '1.2345678900',
            'effective_on' => '2026-11-12',
            'source' => 'verified-reference',
            'status' => FinanceExchangeRate::APPROVED,
        ]);
        $rate->id = 42;

        $result = (new FinanceCurrencyConverter())->convert('100.00', $rate);

        $this->assertSame('100.00', $result['base_amount']);
        $this->assertSame('ETB', $result['base_currency']);
        $this->assertSame('123.457', $result['quote_amount']);
        $this->assertSame('KWD', $result['quote_currency']);
        $this->assertSame('1.2345678900', $result['rate']);
        $this->assertSame(42, $result['rate_id']);
        $this->assertSame('2026-11-12', $result['effective_on']);
        $this->assertSame('HALF_UP', $result['rounding_mode']);
    }

    #[Test]
    public function it_rounds_half_up_for_zero_minor_unit_currencies(): void
    {
        $rate = new FinanceExchangeRate([
            'base_currency' => 'ETB',
            'quote_currency' => 'JPY',
            'rate' => '1.5000000000',
            'effective_on' => '2026-11-12',
            'source' => 'verified-reference',
            'status' => FinanceExchangeRate::APPROVED,
        ]);

        $this->assertSame('2', (new FinanceCurrencyConverter())->convert('1.00', $rate)['quote_amount']);
    }

    #[Test]
    public function it_rejects_pending_rates_and_unsupported_currency_codes(): void
    {
        $converter = new FinanceCurrencyConverter();
        $pending = new FinanceExchangeRate([
            'base_currency' => 'ETB', 'quote_currency' => 'USD', 'rate' => '1.0000000000',
            'effective_on' => '2026-11-12', 'source' => 'unreviewed',
            'status' => FinanceExchangeRate::PENDING,
        ]);

        try {
            $converter->convert('10.00', $pending);
            $this->fail('Pending rates must never be used for conversion.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('approved', $exception->getMessage());
        }

        $unsupported = new FinanceExchangeRate([
            'base_currency' => 'ETB', 'quote_currency' => 'XYZ', 'rate' => '1.0000000000',
            'effective_on' => '2026-11-12', 'source' => 'verified-reference',
            'status' => FinanceExchangeRate::APPROVED,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $converter->convert('10.00', $unsupported);
    }

    #[Test]
    public function it_rejects_amounts_exceeding_the_base_currency_precision(): void
    {
        $rate = new FinanceExchangeRate([
            'base_currency' => 'ETB', 'quote_currency' => 'USD', 'rate' => '1.0000000000',
            'effective_on' => '2026-11-12', 'source' => 'verified-reference',
            'status' => FinanceExchangeRate::APPROVED,
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new FinanceCurrencyConverter())->convert('10.001', $rate);
    }
}
