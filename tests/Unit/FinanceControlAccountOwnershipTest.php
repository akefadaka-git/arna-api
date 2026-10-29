<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceControlAccountOwnershipTest extends TestCase
{
    #[Test]
    public function every_control_account_has_an_accountable_role_and_independent_review_role(): void
    {
        $chart = require base_path('config/finance/chart_of_accounts_v1.php');
        $ownership = require base_path('config/finance/control_account_ownership_v1.php');

        $controlCodes = [];
        foreach ($chart as $row) {
            if (($row[7] ?? false) === true) {
                $controlCodes[] = (string) $row[0];
            }
        }

        $this->assertNotEmpty($controlCodes);
        $this->assertSame([], array_values(array_diff($controlCodes, array_keys($ownership))), 'Every control account must have an ownership policy.');
        $this->assertSame([], array_values(array_diff(array_keys($ownership), $controlCodes)), 'Ownership policies must not reference non-control or unknown accounts.');

        foreach ($ownership as $code => $policy) {
            $this->assertNotSame('', trim((string) ($policy['accountable_role'] ?? '')), "Account {$code} requires an accountable role.");
            $this->assertNotSame('', trim((string) ($policy['preparer_role'] ?? '')), "Account {$code} requires a preparer role.");
            $this->assertNotSame('', trim((string) ($policy['reviewer_role'] ?? '')), "Account {$code} requires a reviewer role.");
            $this->assertNotSame($policy['preparer_role'], $policy['reviewer_role'], "Account {$code} must separate preparation and review roles.");
            $this->assertNotEmpty($policy['frequency'] ?? null, "Account {$code} requires a reconciliation frequency.");
            $this->assertNotEmpty($policy['evidence'] ?? null, "Account {$code} requires an evidence schedule.");
            $this->assertNotEmpty($policy['escalation_role'] ?? null, "Account {$code} requires an escalation role.");
        }
    }

    #[Test]
    public function owner_configuration_explicitly_requires_real_personnel_assignment_before_production(): void
    {
        $config = file_get_contents(base_path('config/finance/control_account_ownership_v1.php'));

        $this->assertIsString($config);
        $this->assertStringContainsString('not named employee appointments', $config);
        $this->assertStringContainsString('assign active user IDs', $config);
        $this->assertStringContainsString('does not activate subledger posting', $config);
    }
}
