<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentNumberingContractTest extends TestCase
{
    #[Test]
    public function it_formats_the_canonical_amharic_number_with_all_six_segments(): void
    {
        $formatter = new DocumentNumberFormatter();

        $this->assertSame('ማዓ/01/03/04/2019/0001', $formatter->format('01', '03', 'INV', 2019, 1));
        $this->assertSame('ማዓ/99/12/15/2000/9999', $formatter->format('99', '12', 'RCT', 2000, 9999));
    }

    #[Test]
    public function it_maps_all_document_families_to_stable_numeric_codes(): void
    {
        $this->assertSame('12', DocumentTypeRegistry::numericCode('PUR'));
        $this->assertSame('13', DocumentTypeRegistry::numericCode('EXP'));
        $this->assertSame('14', DocumentTypeRegistry::numericCode('INC'));
        $this->assertSame('15', DocumentTypeRegistry::numericCode('RCT'));
        $this->assertSame('CER', DocumentTypeRegistry::legacyCode('05'));
        $this->assertCount(15, DocumentTypeRegistry::CODES);
    }

    #[Test]
    public function it_rejects_invalid_entity_department_type_sequence_and_year_values(): void
    {
        $formatter = new DocumentNumberFormatter();

        foreach ([
            ['00', '03', 'INV', 2019, 1],
            ['1A', '03', 'INV', 2019, 1],
            ['01', '00', 'INV', 2019, 1],
            ['01', 'A3', 'INV', 2019, 1],
            ['01', '03', 'UNKNOWN', 2019, 1],
            ['01', '03', 'INV', 999, 1],
            ['01', '03', 'INV', 10000, 1],
            ['01', '03', 'INV', 2019, 0],
            ['01', '03', 'INV', 2019, 10000],
        ] as [$entity, $department, $type, $year, $sequence]) {
            try {
                $formatter->format($entity, $department, $type, $year, $sequence);
                $this->fail('Invalid numbering input was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
