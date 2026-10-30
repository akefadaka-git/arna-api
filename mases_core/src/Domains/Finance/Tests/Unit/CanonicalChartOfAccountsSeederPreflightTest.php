<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use LogicException;
use MaaleArna\MASES\Core\Domains\Finance\Services\CanonicalChartOfAccountsSeederPreflight;
use PHPUnit\Framework\TestCase;

final class CanonicalChartOfAccountsSeederPreflightTest extends TestCase
{
    public function test_non_system_conflict_in_any_entity_fails_preflight(): void
    {
        $entities = [
            (object) ['id' => 10, 'tenant_id' => 1],
            (object) ['id' => 20, 'tenant_id' => 2],
        ];
        $existing = [
            '10' => [],
            '20' => [(object) [
                'tenant_id' => 2,
                'legal_entity_id' => 20,
                'code' => '1110',
                'is_system_account' => false,
            ]],
        ];

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsSeederPreflight())->validate([['1110']], $entities, $existing);
    }

    public function test_existing_system_account_is_allowed(): void
    {
        $entities = [(object) ['id' => 10, 'tenant_id' => 1]];
        $existing = [
            '10' => [(object) [
                'tenant_id' => 1,
                'legal_entity_id' => 10,
                'code' => '1110',
                'is_system_account' => true,
            ]],
        ];

        (new CanonicalChartOfAccountsSeederPreflight())->validate([['1110']], $entities, $existing);
        self::assertTrue(true);
    }

    public function test_duplicate_existing_canonical_codes_are_rejected(): void
    {
        $entities = [(object) ['id' => 10, 'tenant_id' => 1]];
        $existing = [
            '10' => [
                (object) ['tenant_id' => 1, 'legal_entity_id' => 10, 'code' => '1110', 'is_system_account' => true],
                (object) ['tenant_id' => 1, 'legal_entity_id' => 10, 'code' => '1110', 'is_system_account' => true],
            ],
        ];

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsSeederPreflight())->validate([['1110']], $entities, $existing);
    }

    public function test_existing_rows_must_match_the_entity_tenant_boundary(): void
    {
        $entities = [(object) ['id' => 10, 'tenant_id' => 1]];
        $existing = [
            '10' => [(object) [
                'tenant_id' => 2,
                'legal_entity_id' => 10,
                'code' => '9900',
                'is_system_account' => false,
            ]],
        ];

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsSeederPreflight())->validate([['1110']], $entities, $existing);
    }

    public function test_noncanonical_tenant_owned_accounts_are_not_touched_by_preflight(): void
    {
        $entities = [(object) ['id' => 10, 'tenant_id' => 1]];
        $existing = [
            '10' => [(object) [
                'tenant_id' => 1,
                'legal_entity_id' => 10,
                'code' => '9900',
                'is_system_account' => false,
            ]],
        ];

        (new CanonicalChartOfAccountsSeederPreflight())->validate([['1110']], $entities, $existing);
        self::assertTrue(true);
    }

}
