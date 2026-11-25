<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Tests\Unit;

use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderCsvPreviewService;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderImportPreviewValidator;
use Tests\TestCase;

final class ShareholderImportPreviewServiceTest extends TestCase
{
    public function test_valid_amharic_individual_row_is_previewed_without_writes(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,first_name_am,father_name_am\nSRC-001,individual,አቀፋ,አየለ\n",
        );

        $this->assertSame('preview_only', $result['mode']);
        $this->assertFalse($result['writes_performed']);
        $this->assertFalse($result['source_file_stored']);
        $this->assertTrue($result['can_proceed_to_manual_review']);
        $this->assertSame(1, $result['summary']['valid']);
        $this->assertSame('አቀፋ', $result['rows'][0]['identity']['first_name_am']);
    }

    public function test_legal_entity_requires_amharic_name_and_representative_reference(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,entity_name_am,representative_reference\nSRC-002,company,ማሌ አርና,REP-01\n",
        );

        $this->assertTrue($result['rows'][0]['valid']);
        $this->assertSame('REP-01', $result['rows'][0]['identity']['representative_reference']);
    }

    public function test_individual_missing_required_amharic_names_is_invalid(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,first_name_am\nSRC-003,individual,አቀፋ\n",
        );

        $this->assertFalse($result['can_proceed_to_manual_review']);
        $this->assertArrayHasKey('father_name_am', $result['rows'][0]['errors']);
    }

    public function test_unknown_shareholder_type_is_rejected(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,first_name_am,father_name_am\nSRC-004,unknown,አቀፋ,አየለ\n",
        );

        $this->assertFalse($result['rows'][0]['valid']);
        $this->assertArrayHasKey('shareholder_type', $result['rows'][0]['errors']);
    }

    public function test_duplicate_source_references_are_rejected(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,first_name_am,father_name_am\nSRC-005,individual,አቀፋ,አየለ\nsrc-005,individual,ሌላ,ሰው\n",
        );

        $this->assertTrue($result['rows'][0]['valid']);
        $this->assertFalse($result['rows'][1]['valid']);
        $this->assertArrayHasKey('source_reference', $result['rows'][1]['errors']);
    }

    public function test_capital_and_share_quantity_columns_are_rejected(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,first_name_am,father_name_am,share_quantity\nSRC-006,individual,አቀፋ,አየለ,10\n",
        );

        $this->assertFalse($result['can_proceed_to_manual_review']);
        $this->assertStringContainsString('Unsupported CSV columns', $result['file_errors'][0]);
    }

    public function test_duplicate_headers_are_rejected(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,source_reference,shareholder_type\nSRC-007,SRC-008,individual\n",
        );

        $this->assertStringContainsString('Duplicate CSV column names', $result['file_errors'][0]);
    }

    public function test_utf8_bom_is_supported(): void
    {
        $result = $this->csvService()->preview(
            "\xEF\xBB\xBFsource_reference,shareholder_type,first_name_am,father_name_am\nSRC-009,individual,አቀፋ,አየለ\n",
        );

        $this->assertTrue($result['can_proceed_to_manual_review']);
    }

    public function test_csv_row_with_extra_values_is_rejected(): void
    {
        $result = $this->csvService()->preview(
            "source_reference,shareholder_type,first_name_am,father_name_am\nSRC-010,individual,አቀፋ,አየለ,unexpected\n",
        );

        $this->assertFalse($result['rows'][0]['valid']);
        $this->assertArrayHasKey('row', $result['rows'][0]['errors']);
    }

    private function csvService(): ShareholderCsvPreviewService
    {
        return new ShareholderCsvPreviewService(new ShareholderImportPreviewValidator());
    }
}
