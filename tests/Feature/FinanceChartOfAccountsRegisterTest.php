<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Database-free contract tests for the canonical Finance CoA register.
 * These tests do not approve tax policy or activate the seeder.
 */
final class FinanceChartOfAccountsRegisterTest extends TestCase
{
    private function register(): array
    {
        return require base_path('config/finance/chart_of_accounts_v1.php');
    }

    #[Test]
    public function canonical_register_has_unique_codes_and_complete_rows(): void
    {
        $register = $this->register();
        $this->assertCount(79, $register, 'CoA v1.0.0 must contain the reviewed 79-account baseline.');

        $codes = [];
        foreach ($register as $row) {
            $this->assertIsArray($row);
            $this->assertCount(10, $row, 'Each CoA row must contain exactly ten fields.');
            $code = $row[0];
            $this->assertIsString($code);
            $this->assertMatchesRegularExpression('/^[0-9]{4}$/', $code);
            $this->assertArrayNotHasKey($code, $codes, 'Account codes must be unique.');
            $codes[$code] = true;
        }
    }

    #[Test]
    public function parents_precede_children_and_all_fields_use_supported_values(): void
    {
        $seen = [];
        $taxCategories = ['taxable', 'non_taxable', 'exempt', 'zero_rated', 'out_of_scope', 'undetermined'];
        $types = ['asset', 'liability', 'equity', 'revenue', 'expense'];
        $normalBalances = ['debit', 'credit'];

        foreach ($this->register() as $row) {
            [$code, $parent, $nameEn, $nameAm, $type, $normal, $posting, $control, $domain, $tax] = $row;

            if ($parent !== null) {
                $this->assertArrayHasKey($parent, $seen, "Parent {$parent} must precede child {$code}.");
            }

            $this->assertNotSame('', trim((string) $nameEn), "Account {$code} requires an English label.");
            $this->assertNotSame('', trim((string) $nameAm), "Account {$code} requires an Amharic label.");
            $this->assertContains($type, $types, "Account {$code} has an unsupported account type.");
            $this->assertContains($normal, $normalBalances, "Account {$code} has an unsupported normal balance.");
            $this->assertContains($tax, $taxCategories, "Account {$code} has an unsupported tax category.");
            $this->assertIsBool($posting);
            $this->assertIsBool($control);
            $this->assertNotSame('', trim((string) $domain), "Account {$code} requires a domain label.");

            if ($control) {
                $this->assertTrue($posting, "Control account {$code} must be posting-eligible.");
            }

            $seen[$code] = true;
        }
    }

    #[Test]
    public function account_tax_defaults_are_routing_metadata_not_rates_or_final_tax_decisions(): void
    {
        foreach ($this->register() as $row) {
            $this->assertIsString($row[9]);
            $this->assertNotSame('', trim($row[9]));
            $this->assertNotContains($row[9], ['0', '5', '15', '30'], 'Tax categories must not encode tax rates.');
        }

        $this->assertTrue(true, 'Transaction-level tax determination and qualified Ethiopian review remain separate approval gates.');
    }
}
