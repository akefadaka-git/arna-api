<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceFoundationSchemaTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    #[Test]
    public function clean_schema_has_one_canonical_journal_type_column(): void
    {
        $this->assertTrue(Schema::hasColumn('journal_entries', 'journal_type'));
        $columns = collect(Schema::getColumns('journal_entries'))->pluck('name')->all();
        $this->assertSame(1, count(array_filter($columns, static fn (string $name): bool => $name === 'journal_type')));
    }

    #[Test]
    public function finance_proposals_use_versioned_unique_keys(): void
    {
        $this->assertTrue(Schema::hasColumn('finance_consolidation_account_mappings', 'proposal_version'));
        $this->assertTrue(Schema::hasColumn('finance_exchange_rates', 'proposal_version'));

        $mappingIndexes = Schema::getIndexes('finance_consolidation_account_mappings');
        $rateIndexes = Schema::getIndexes('finance_exchange_rates');
        $mappingUniqueKeys = array_map(static fn (array $index): array => $index['columns'], array_filter($mappingIndexes, static fn (array $index): bool => $index['unique']));
        $rateUniqueKeys = array_map(static fn (array $index): array => $index['columns'], array_filter($rateIndexes, static fn (array $index): bool => $index['unique']));

        $this->assertContains(['tenant_id', 'legal_entity_id', 'ledger_account_id', 'proposal_version'], array_values($mappingUniqueKeys));
        $this->assertContains(['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'proposal_version'], array_values($rateUniqueKeys));
    }
}
