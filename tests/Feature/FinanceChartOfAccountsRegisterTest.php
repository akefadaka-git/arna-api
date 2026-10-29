<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceChartOfAccountsRegisterTest extends TestCase
{
    #[Test]
    public function canonical_register_has_unique_codes_and_valid_parent_order(): void
    {
        $register = require base_path('config/finance/chart_of_accounts_v1.php');
        $this->assertCount(79, $register);

        $seen = [];
        foreach ($register as $row) {
            $this->assertCount(10, $row);
            [$code, $parent, $nameEn, $nameAm, $type, $normal, $posting, $control, $domain, $tax] = $row;
            $this->assertArrayNotHasKey($code, $seen, "Duplicate CoA code {$code}");
            if ($parent !== null) {
                $this->assertArrayHasKey($parent, $seen, "Parent {$parent} must precede {$code}");
            }
            $this->assertContains($type, ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $this->assertContains($normal, ['debit', 'credit']);
            $this->assertContains($tax, ['taxable', 'non_taxable', 'exempt', 'zero_rated', 'out_of_scope', 'undetermined']);
            if ($control) {
                $this->assertTrue($posting, "Control account {$code} must be postable");
                $this->assertNotSame('', trim((string) $domain), "Control account {$code} requires a domain");
            }
            $seen[$code] = true;
        }
    }

    #[Test]
    public function every_canonical_control_account_has_a_blocked_until_designated_owner_record(): void
    {
        $register = require base_path('config/finance/chart_of_accounts_v1.php');
        $ownership = require base_path('config/finance/control_account_ownership_v1.php');
        $controlCodes = [];
        foreach ($register as $row) {
            if ($row[7] === true) {
                $controlCodes[] = $row[0];
            }
        }
        $ownerCodes = array_keys($ownership);
        sort($controlCodes);
        sort($ownerCodes);

        $this->assertSame($controlCodes, $ownerCodes);
        foreach ($ownership as $code => $owner) {
            $this->assertNotSame('', $owner['accountable_role'] ?? '');
            $this->assertNotEmpty($owner['required_evidence'] ?? []);
            $this->assertNotSame('', $owner['frequency'] ?? '');
            $this->assertNull($owner['named_accountable_person'] ?? null);
            $this->assertSame('PENDING_OWNER_DESIGNATION', $owner['designation_status'] ?? null);
            $this->assertSame('BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED', $owner['adapter_activation'] ?? null);
        }
    }
}
