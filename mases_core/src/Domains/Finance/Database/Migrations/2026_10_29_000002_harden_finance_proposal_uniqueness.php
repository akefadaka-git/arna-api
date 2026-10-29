<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('finance_consolidation_account_mappings', 'proposal_version')) {
            Schema::table('finance_consolidation_account_mappings', function (Blueprint $table): void {
                $table->unsignedInteger('proposal_version')->default(1);
            });
        }

        if (!Schema::hasColumn('finance_exchange_rates', 'proposal_version')) {
            Schema::table('finance_exchange_rates', function (Blueprint $table): void {
                $table->unsignedInteger('proposal_version')->default(1);
            });
        }

        $this->backfillMappingVersions();
        $this->backfillExchangeRateVersions();

        $this->dropUniqueIfPresent(
            'finance_consolidation_account_mappings',
            'finance_consolidation_mapping_account_status_unique'
        );
        $this->dropUniqueIfPresent(
            'finance_exchange_rates',
            'finance_exchange_rate_pair_date_status_unique'
        );

        $this->addUniqueIfMissing(
            'finance_consolidation_account_mappings',
            ['tenant_id', 'legal_entity_id', 'ledger_account_id', 'proposal_version'],
            'finance_consolidation_mapping_proposal_version_unique'
        );
        $this->addUniqueIfMissing(
            'finance_exchange_rates',
            ['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'proposal_version'],
            'finance_exchange_rate_proposal_version_unique'
        );
    }

    public function down(): void
    {
        $this->dropUniqueIfPresent(
            'finance_consolidation_account_mappings',
            'finance_consolidation_mapping_proposal_version_unique'
        );
        $this->dropUniqueIfPresent(
            'finance_exchange_rates',
            'finance_exchange_rate_proposal_version_unique'
        );

        if (Schema::hasColumn('finance_consolidation_account_mappings', 'proposal_version')) {
            Schema::table('finance_consolidation_account_mappings', function (Blueprint $table): void {
                $table->dropColumn('proposal_version');
            });
        }
        if (Schema::hasColumn('finance_exchange_rates', 'proposal_version')) {
            Schema::table('finance_exchange_rates', function (Blueprint $table): void {
                $table->dropColumn('proposal_version');
            });
        }

        $this->addUniqueIfMissing(
            'finance_consolidation_account_mappings',
            ['tenant_id', 'legal_entity_id', 'ledger_account_id', 'status'],
            'finance_consolidation_mapping_account_status_unique'
        );
        $this->addUniqueIfMissing(
            'finance_exchange_rates',
            ['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'status'],
            'finance_exchange_rate_pair_date_status_unique'
        );
    }

    private function backfillMappingVersions(): void
    {
        $versions = [];
        DB::table('finance_consolidation_account_mappings')
            ->orderBy('tenant_id')->orderBy('legal_entity_id')->orderBy('ledger_account_id')->orderBy('id')
            ->get(['id', 'tenant_id', 'legal_entity_id', 'ledger_account_id'])
            ->each(function (object $row) use (&$versions): void {
                $key = implode(':', [$row->tenant_id, $row->legal_entity_id, $row->ledger_account_id]);
                $version = ($versions[$key] ?? 0) + 1;
                $versions[$key] = $version;
                DB::table('finance_consolidation_account_mappings')
                    ->where('id', $row->id)->update(['proposal_version' => $version]);
            });
    }

    private function backfillExchangeRateVersions(): void
    {
        $versions = [];
        DB::table('finance_exchange_rates')
            ->orderBy('tenant_id')->orderBy('base_currency')->orderBy('quote_currency')->orderBy('effective_on')->orderBy('id')
            ->get(['id', 'tenant_id', 'base_currency', 'quote_currency', 'effective_on'])
            ->each(function (object $row) use (&$versions): void {
                $key = implode(':', [$row->tenant_id, $row->base_currency, $row->quote_currency, $row->effective_on]);
                $version = ($versions[$key] ?? 0) + 1;
                $versions[$key] = $version;
                DB::table('finance_exchange_rates')
                    ->where('id', $row->id)->update(['proposal_version' => $version]);
            });
    }

    private function dropUniqueIfPresent(string $table, string $name): void
    {
        $exists = collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['name'] ?? null) === $name
                && (bool) ($index['unique'] ?? false)
        );
        if ($exists) {
            Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropUnique($name);
            });
        }
    }

    /** @param list<string> $columns */
    private function addUniqueIfMissing(string $table, array $columns, string $name): void
    {
        $exists = collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['name'] ?? null) === $name
                && (bool) ($index['unique'] ?? false)
        );
        if (!$exists) {
            Schema::table($table, static function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->unique($columns, $name);
            });
        }
    }
};
