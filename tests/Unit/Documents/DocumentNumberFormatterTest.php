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
    public function it_formats_the_owner_approved_amharic_document_number(): void
    {
        $formatter = new DocumentNumberFormatter();

        $this->assertSame('ዓርና/12/01/0042/19', $formatter->format('12', 'LET', 42, 2019));
        $this->assertSame('ዓርና/99/11/0001/26', $formatter->format('99', 'POL', 1, 2026));
    }

    #[Test]
    public function it_accepts_numeric_type_codes_and_uses_only_the_year_suffix(): void
    {
        $formatter = new DocumentNumberFormatter();

        $this->assertSame('ዓርና/11/02/0001/26', $formatter->format('11', '02', 1, 2026));
        $this->assertSame('ዓርና/21/06/9999/00', $formatter->format('21', '06', 9999, 2100));
    }

    #[Test]
    public function it_preserves_the_owner_approved_numeric_tt_registry_and_legacy_aliases(): void
    {
        $this->assertSame([
            'LET' => '01', 'MEM' => '02', 'LEG' => '03', 'INV' => '04',
            'CER' => '05', 'RES' => '06', 'CIR' => '07', 'RPT' => '08',
            'CON' => '09', 'NOT' => '10', 'POL' => '11',
        ], DocumentTypeRegistry::TYPES);
        $this->assertSame('LET', DocumentTypeRegistry::legacyCode('01'));
        $this->assertSame('01', DocumentTypeRegistry::numericCode('LET'));
        $this->assertSame('documents.issue.LET', DocumentTypeRegistry::authorityAction('01'));
    }

    #[Test]
    public function it_rejects_unsupported_document_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DocumentNumberFormatter())->format('11', 'XYZ', 1, 2019);
    }

    #[Test]
    public function it_rejects_sequences_outside_the_four_digit_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DocumentNumberFormatter())->format('11', 'LET', 10000, 2019);
    }

    #[Test]
    public function it_rejects_a_missing_or_invalid_ec_year(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DocumentNumberFormatter())->format('11', 'LET', 1, 0);
    }

    #[Test]
    public function it_rejects_invalid_branch_or_department_digits(): void
    {
        $formatter = new DocumentNumberFormatter();
        foreach (['00', '01', '10', '1A', '123'] as $bb) {
            try {
                $formatter->format($bb, 'LET', 1, 2019);
                $this->fail('Invalid BB code must be rejected: '.$bb);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
