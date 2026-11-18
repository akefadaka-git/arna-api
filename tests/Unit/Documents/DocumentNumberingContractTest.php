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
    public function it_formats_the_canonical_amharic_number_using_the_full_ethiopian_year(): void
    {
        $formatter = new DocumentNumberFormatter();

        $this->assertSame('ዓርና/11/04/0001/19', $formatter->format('11', 'INV', 1, 2019));
        $this->assertSame('ዓርና/23/15/9999/00', $formatter->format('23', 'RCT', 9999, 2000));
    }

    #[Test]
    public function it_maps_all_a1_document_families_to_stable_numeric_codes(): void
    {
        $this->assertSame('12', DocumentTypeRegistry::numericCode('PUR'));
        $this->assertSame('13', DocumentTypeRegistry::numericCode('EXP'));
        $this->assertSame('14', DocumentTypeRegistry::numericCode('INC'));
        $this->assertSame('15', DocumentTypeRegistry::numericCode('RCT'));
        $this->assertSame('CER', DocumentTypeRegistry::legacyCode('05'));
        $this->assertCount(15, DocumentTypeRegistry::CODES);
    }

    #[Test]
    public function it_rejects_invalid_bb_type_sequence_and_year_values(): void
    {
        $formatter = new DocumentNumberFormatter();

        foreach ([
            ['01', 'INV', 1, 2019],
            ['1A', 'INV', 1, 2019],
            ['11', 'UNKNOWN', 1, 2019],
            ['11', 'INV', 0, 2019],
            ['11', 'INV', 10000, 2019],
            ['11', 'INV', 1, 0],
        ] as [$bb, $type, $sequence, $year]) {
            try {
                $formatter->format($bb, $type, $sequence, $year);
                $this->fail('Invalid numbering input was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
