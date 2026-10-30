<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Domains\Finance\Services\CanonicalChartOfAccountsValidator;
use MaaleArna\MASES\Core\Domains\Finance\Services\CanonicalChartOfAccountsSeederPreflight;
use MaaleArna\MASES\Core\Domains\Finance\Services\ControlAccountOwnershipRegistry;
use RuntimeException;

/**
 * Seeds canonical ARNA CoA v1.0.0 for each active legal entity.
 * Idempotency is keyed by (tenant_id, legal_entity_id, code). Only rows
 * marked is_system_account=true are maintained. Existing tenant accounts and
 * posted history are never overwritten; no opening balances are created.
 *
 * Deliberately not wired into DatabaseSeeder until Owner approval.
 */
final class FinanceChartOfAccountsSeeder extends Seeder
{
    public const VERSION = '1.0.0';

    public function run(): void
    {
        $required = [
            'tenant_id', 'legal_entity_id', 'parent_id', 'code', 'name_am', 'name_en',
            'account_type', 'normal_balance', 'is_posting_allowed', 'status',
            'is_control_account', 'control_domain', 'default_tax_category',
            'coa_version', 'is_system_account',
        ];
        if (!Schema::hasTable('legal_entities') || !Schema::hasTable('ledger_accounts')) {
            throw new RuntimeException('ARNA CoA v1.0.0 requires legal_entities and ledger_accounts migrations.');
        }
        foreach (['id', 'tenant_id', 'status'] as $column) {
            if (!Schema::hasColumn('legal_entities', $column)) {
                throw new RuntimeException("ARNA CoA v1.0.0 requires legal_entities.{$column}; verify the legal-entity schema before seeding.");
            }
        }
        foreach ($required as $column) {
            if (!Schema::hasColumn('ledger_accounts', $column)) {
                throw new RuntimeException("ARNA CoA v1.0.0 requires ledger_accounts.{$column}; run migrations first.");
            }
        }

        $register = config('finance.chart_of_accounts_v1', []);
        app(CanonicalChartOfAccountsValidator::class)->validate($register);
        // Validate control-account ownership before the first database write.
        // Pending personnel designation is allowed for seeding, but the registry
        // enforces that reconciliation adapters remain blocked until approval.
        app(ControlAccountOwnershipRegistry::class)->validateAgainstChart($register);

        DB::transaction(function () use ($register): void {
            // Lock all active entities and all existing account rows, then
            // preflight every entity before the first canonical account write.
            $entities = DB::table('legal_entities')
                ->where('status', 'active')
                ->orderBy('tenant_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($entities->isEmpty()) {
                throw new RuntimeException('ARNA CoA v1.0.0 found no active legal entities; no accounts were seeded.');
            }

            $existingByEntity = [];
            foreach ($entities as $entity) {
                $existingByEntity[(string) $entity->id] = DB::table('ledger_accounts')
                    ->where('tenant_id', $entity->tenant_id)
                    ->where('legal_entity_id', $entity->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->all();
            }

            app(CanonicalChartOfAccountsSeederPreflight::class)->validate(
                $register,
                $entities->all(),
                $existingByEntity
            );

            // The complete multi-entity preflight passed. Writes remain inside
            // this one transaction, so a later entity failure rolls everything back.
            foreach ($entities as $entity) {
                $existing = collect($existingByEntity[(string) $entity->id])->keyBy('code');
                $ids = [];

                foreach ($register as $row) {
                    $code = $row[0];
                    $found = $existing->get($code);
                    $ids[$code] = $found->id ?? null;
                }

                foreach ($register as $row) {
                    [$code, $parentCode, $nameEn, $nameAm, $type, $normal, $posting, $control, $domain, $tax] = $row;
                    $parentId = $parentCode === null ? null : $ids[$parentCode];
                    $values = [
                        'tenant_id' => $entity->tenant_id,
                        'legal_entity_id' => $entity->id,
                        'parent_id' => $parentId,
                        'code' => $code,
                        'name_en' => $nameEn,
                        'name_am' => $nameAm,
                        'account_type' => $type,
                        'normal_balance' => $normal,
                        'is_posting_allowed' => $posting,
                        'status' => 'active',
                        'is_control_account' => $control,
                        'control_domain' => $control ? $domain : null,
                        'default_tax_category' => $tax,
                        'coa_version' => self::VERSION,
                        'is_system_account' => true,
                        'updated_at' => now(),
                    ];

                    if ($ids[$code] === null) {
                        $values['created_at'] = now();
                        $ids[$code] = DB::table('ledger_accounts')->insertGetId($values);
                    } else {
                        DB::table('ledger_accounts')
                            ->where('id', $ids[$code])
                            ->where('tenant_id', $entity->tenant_id)
                            ->where('legal_entity_id', $entity->id)
                            ->where('is_system_account', true)
                            ->update($values);
                    }
                }
            }
        });
    }

}
