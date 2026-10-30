<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentNumberFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_the_approved_compact_ethiopian_document_number(): void
    {
        $formatter = new DocumentNumberFormatter();

        $this->assertSame(
            'AR-LET-HQ-CEO-0042/19',
            $formatter->format('LET', 'HQ', 'CEO', 42, 2019),
        );
    }

    #[Test]
    public function it_zero_pads_the_sequence_and_uses_only_the_year_suffix(): void
    {
        $formatter = new DocumentNumberFormatter();

        $this->assertSame('AR-MEM-HQ-CEO-0001/26', $formatter->format('MEM', 'HQ', 'CEO', 1, 2026));
        $this->assertSame('AR-RES-HQ-CEO-9999/00', $formatter->format('RES', 'HQ', 'CEO', 9999, 2100));
    }

    #[Test]
    public function it_rejects_unsupported_document_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DocumentNumberFormatter())->format('XYZ', 'HQ', 'CEO', 1, 2019);
    }

    #[Test]
    public function it_rejects_sequences_outside_the_four_digit_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DocumentNumberFormatter())->format('LET', 'HQ', 'CEO', 10000, 2019);
    }

    #[Test]
    public function it_rejects_a_missing_or_invalid_ec_year(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DocumentNumberFormatter())->format('LET', 'HQ', 'CEO', 1, 0);
    }

    #[Test]
    public function it_rejects_invalid_department_and_role_codes(): void
    {
        $formatter = new DocumentNumberFormatter();

        try {
            $formatter->format('LET', 'HQ/BRANCH', 'CEO', 1, 2019);
            $this->fail('A department code containing a separator must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $formatter->format('LET', 'HQ', 'CEO/ACTING', 1, 2019);
    }
}
