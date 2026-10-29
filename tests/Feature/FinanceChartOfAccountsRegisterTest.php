<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fast, database-free contract tests for the canonical Finance CoA register.
 * These tests intentionally do not approve tax policy or activate seeding.
 */
final class FinanceChartOfAccountsRegisterTest extends TestCase
{
    private function register(): array
    {
        return require base_path('config/finance/chart_of_accounts_v1.php');
    }

    #[Test]
    public function canonical_register_has_unique_codes_and_complete_ten_field_rows(): void
    {
        $register = $this->register();
        $this->assertCount(79, $register, 'CoA v1.0.0 must contain the reviewed 79-account baseline.');

        $codes = [];
        foreach ($register as $row) {
            $this->assertCount(10, $row, 'Each account row must contain code, parent, labels, type, normal balance, posting/control flags, domain, and tax default.');
            $this->assertIsString($row[0]);
            $this->assertNotSame('', trim($row[0]));
            $this->assertArrayNotHasKey($row[0], $codes, 'Account codes must be unique.');
            $codes[$row[0]] = true;
        }
    }

    #[Test]
    public function every_parent_precedes_its_child_and_every_tax_default_is_supported(): void
    {
        $register = $this->register();
        $seen = [];
        $taxCategories = ['taxable', 'non_taxable', 'exempt', 'zero_rated', 'out_of_scope', 'undetermined'];
        $types = ['asset', 'liability', 'equity', 'revenue', 'expense'];
        $normalBalances = ['debit', 'credit'];

        foreach ($register as $row) {
            [$code, $parent, $nameEn, $nameAm, $type, $normal, $posting, $control, $domain, $tax] = $row;

            if ($parent !== null) {
                $this->assertArrayHasKey($parent, $seen, "Parent {$parent} must appear before child {$code}.");
            }

            $this->assertNotSame('', trim((string) $nameEn), "Account {$code} needs an English label.");
            $this->assertNotSame('', trim((string) $nameAm), "Account {$code} needs an Amharic label.");
            $this->assertContains($type, $types, "Account {$code} has an unsupported type.");
            $this->assertContains($normal, $normalBalances, "Account {$code} has an unsupported normal balance.");
            $this->assertContains($tax, $taxCategories, "Account {$code} has an unsupported tax routing default.");
            $this->assertIsBool($posting);
            $this->assertIsBool($control);
            $this->assertNotSame('', trim((string) $domain), "Account {$code} needs an accountable domain label.");

            if ($control) {
                $this->assertTrue($posting, "Control account {$code} must be posting-eligible.");
            }

            $seen[$code] = true;
        }
    }

    #[Test]
    public function account_tax_defaults_are_routing_metadata_not_a_tax_rate_or_final_tax_decision(): void
    {
        foreach ($this->register() as $row) {
            $this->assertIsString($row[9]);
            $this->assertNotSame('', trim($row[9]));
            $this->assertNotContains($row[9], ['0', '5', '15', '30'], 'A tax category must not be encoded as a tax rate.');
        }

        $this->assertTrue(true, 'Transaction-level tax determination and qualified Ethiopian review remain separate approval gates.');
    }
}
