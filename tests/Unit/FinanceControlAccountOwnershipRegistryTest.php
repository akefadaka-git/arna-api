<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Finance\Services\ControlAccountOwnershipRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceControlAccountOwnershipRegistryTest extends TestCase
{
    #[Test]
    public function every_canonical_control_account_has_a_complete_fail_closed_policy(): void
    {
        $chart = require base_path('config/finance/chart_of_accounts_v1.php');
        $registry = new ControlAccountOwnershipRegistry();

        $registry->validateAgainstChart($chart);

        $controlCodes = [];
        foreach ($chart as $row) {
            if (($row[7] ?? false) === true) {
                $controlCodes[] = (string) $row[0];
            }
        }

        $this->assertCount(36, $controlCodes);
        $this->assertCount(36, $registry->all());

        foreach ($controlCodes as $code) {
            $policy = $registry->forAccountCode($code);
            $this->assertNotSame('', trim((string) ($policy['accountable_role'] ?? '')));
            $this->assertNotSame('', trim((string) ($policy['independent_reviewer_role'] ?? '')));
            $this->assertNotSame('', trim((string) ($policy['frequency'] ?? '')));
            $this->assertNotEmpty($policy['required_evidence'] ?? []);
            $this->assertNotEmpty($policy['contributing_domains'] ?? []);
            $this->assertSame('ROLE_DESIGNATED_PERSON_PENDING', $policy['designation_status']);
            $this->assertSame('Owner-authorized functional role designation; named person pending', $policy['designation_basis']);
            $this->assertNull($policy['named_accountable_person']);
            $this->assertSame(
                'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED',
                $policy['adapter_activation']
            );
        }
    }

    #[Test]
    public function unknown_or_blank_account_codes_are_rejected(): void
    {
        $registry = new ControlAccountOwnershipRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->forAccountCode('  ');
    }

    #[Test]
    public function a_policy_for_an_unknown_account_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ControlAccountOwnershipRegistry())->forAccountCode('9999');
    }

    #[Test]
    public function incomplete_or_inconsistent_policy_coverage_is_rejected(): void
    {
        $registry = new ControlAccountOwnershipRegistry();
        $chart = require base_path('config/finance/chart_of_accounts_v1.php');
        $chart[] = $chart[0];
        $this->expectException(LogicException::class);
        $registry->validateAgainstChart($chart);
    }
}
