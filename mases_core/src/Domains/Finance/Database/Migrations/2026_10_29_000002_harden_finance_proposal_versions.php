<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Status-based uniqueness made it impossible to submit a second
        // rejected proposal for the same natural key. Version proposals
        // instead, while services serialize writes on the parent account or
        // tenant row and refuse competing pending/approved proposals.
        Schema::table('finance_consolidation_account_mappings', function (Blueprint $table): void {
            $table->dropUnique('finance_consolidation_mapping_account_status_unique');
            $table->unsignedInteger('proposal_version')->default(1);
        });
        Schema::table('finance_exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('finance_exchange_rate_pair_date_status_unique');
            $table->unsignedInteger('proposal_version')->default(1);
        });

        $this->backfillVersions('finance_consolidation_account_mappings', ['tenant_id', 'legal_entity_id', 'ledger_account_id']);
        $this->backfillVersions('finance_exchange_rates', ['tenant_id', 'base_currency', 'quote_currency', 'effective_on']);

        Schema::table('finance_consolidation_account_mappings', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'legal_entity_id', 'ledger_account_id', 'proposal_version'], 'finance_consolidation_mapping_proposal_version_unique');
            $table->index(['tenant_id', 'legal_entity_id', 'ledger_account_id', 'status'], 'finance_consolidation_mapping_account_status_index');
        });
        Schema::table('finance_exchange_rates', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'proposal_version'], 'finance_exchange_rate_proposal_version_unique');
            $table->index(['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'status'], 'finance_exchange_rate_pair_date_status_index');
        });
    }

    /** @param list<string> $keyColumns */
    private function backfillVersions(string $tableName, array $keyColumns): void
    {
        $groups = DB::table($tableName)->select($keyColumns)->distinct()->get();
        foreach ($groups as $group) {
            $key = [];
            foreach ($keyColumns as $column) {
                $key[$column] = $group->{$column};
            }
            $rows = DB::table($tableName)->where($key)->orderBy('id')->get(['id']);
            $version = 0;
            foreach ($rows as $row) {
                DB::table($tableName)->where('id', $row->id)->update(['proposal_version' => ++$version]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            ['finance_consolidation_account_mappings', ['tenant_id', 'legal_entity_id', 'ledger_account_id', 'status']],
            ['finance_exchange_rates', ['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'status']],
        ] as [$tableName, $groupColumns]) {
            if (DB::table($tableName)->select($groupColumns)->groupBy($groupColumns)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Cannot roll back Finance proposal versions without collapsing duplicate proposal statuses; preserve history and use a forward migration.');
            }
        }

        Schema::table('finance_consolidation_account_mappings', function (Blueprint $table): void {
            $table->dropUnique('finance_consolidation_mapping_proposal_version_unique');
            $table->dropIndex('finance_consolidation_mapping_account_status_index');
            $table->dropColumn('proposal_version');
            $table->unique(['tenant_id', 'legal_entity_id', 'ledger_account_id', 'status'], 'finance_consolidation_mapping_account_status_unique');
        });
        Schema::table('finance_exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('finance_exchange_rate_proposal_version_unique');
            $table->dropIndex('finance_exchange_rate_pair_date_status_index');
            $table->dropColumn('proposal_version');
            $table->unique(['tenant_id', 'base_currency', 'quote_currency', 'effective_on', 'status'], 'finance_exchange_rate_pair_date_status_unique');
        });
    }
};
