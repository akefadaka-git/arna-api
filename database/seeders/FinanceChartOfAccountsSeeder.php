<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $register = require base_path('config/finance/chart_of_accounts_v1.php');
        $this->validateRegister($register);
        // Validate control-account ownership before the first database write.
        // Pending personnel designation is allowed for seeding, but the registry
        // enforces that reconciliation adapters remain blocked until approval.
        app(ControlAccountOwnershipRegistry::class)->validateAgainstChart($register);

        $entities = DB::table('legal_entities')->where('status', 'active')->orderBy('tenant_id')->orderBy('id')->get();
        if ($entities->isEmpty()) {
            throw new RuntimeException('ARNA CoA v1.0.0 found no active legal entities; no accounts were seeded.');
        }

        foreach ($entities as $entity) {
            DB::transaction(function () use ($entity, $register): void {
                $existing = DB::table('ledger_accounts')
                    ->where('tenant_id', $entity->tenant_id)
                    ->where('legal_entity_id', $entity->id)
                    ->get()->keyBy('code');

                $ids = [];
                foreach ($register as $row) {
                    $code = $row[0];
                    $found = $existing->get($code);
                    if ($found && !(bool)($found->is_system_account ?? false)) {
                        throw new RuntimeException("CoA code {$code} already exists as a non-system account for legal entity {$entity->id}; refusing overwrite.");
                    }
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
                        DB::table('ledger_accounts')->where('id', $ids[$code])
                            ->where('tenant_id', $entity->tenant_id)
                            ->where('legal_entity_id', $entity->id)
                            ->where('is_system_account', true)
                            ->update($values);
                    }
                }
            });
        }
    }

    private function validateRegister(array $register): void
    {
        $codes = [];
        foreach ($register as $row) {
            if (!is_array($row) || count($row) !== 10) {
                throw new RuntimeException('Every CoA register row must contain exactly 10 fields.');
            }

            [$code, $parent, $en, $am, $type, $normal, $posting, $control, $domain, $tax] = $row;
            if (!is_string($code) || !preg_match('/^[0-9]{4}$/', $code) || isset($codes[$code])) {
                throw new RuntimeException('Invalid or duplicate canonical CoA code: ' . (is_scalar($code) ? (string) $code : gettype($code)));
            }
            if ($parent !== null && (!is_string($parent) || !isset($codes[$parent]))) {
                throw new RuntimeException("Parent code for {$code} must be a preceding canonical account.");
            }
            if (!is_string($en) || trim($en) === '' || !is_string($am) || trim($am) === ''
                || !is_string($domain) || trim($domain) === ''
                || !in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)
                || !in_array($normal, ['debit', 'credit'], true)
                || !in_array($tax, ['taxable', 'non_taxable', 'exempt', 'zero_rated', 'out_of_scope', 'undetermined'], true)
                || !is_bool($posting) || !is_bool($control)) {
                throw new RuntimeException("Invalid required field, type, normal balance, posting/control flag, domain, or tax category for CoA code {$code}.");
            }
            if (!$posting && $control) {
                throw new RuntimeException("Non-posting CoA code {$code} cannot be a control account.");
            }
            if ($control && trim($domain) === '') {
                throw new RuntimeException("Control account {$code} must declare a domain owner.");
            }

            $codes[$code] = true;
        }
    }
}
