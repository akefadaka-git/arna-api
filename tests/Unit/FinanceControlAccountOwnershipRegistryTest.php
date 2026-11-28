<?php

declare(strict_types=1);

namespace Tests\\Unit;

use InvalidArgumentException;
use LogicException;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Services\\FinanceControlAccountOwnershipRegistry;
use PHPUnit\\Framework\\Attributes\\Test;
use Tests\\TestCase;

final class FinanceControlAccountOwnershipRegistryTest extends TestCase
{
    #[Test]
    public function every_canonical_control_account_has_a_complete_fail_closed_policy(): void
    {
        $chart = require base_path('arna_core/src/Domains/Finance/Config/chart_of_accounts_v1.php');
        $registry = new FinanceControlAccountOwnershipRegistry();

        $registry->validateAgainstChart($chart);

        $controlCodes = [];
        foreach ($chart as $row) {
            if (($row[7] ?? false) === true) {
                $controlCodes[] = (string) $row[0];
            }
        }

        self::assertCount(36, $controlCodes);
        self::assertCount(36, $registry->all());

        foreach ($controlCodes as $code) {
            $policy = $registry->forAccountCode($code);
            self::assertNotSame('', trim((string) $policy['accountable_role']));
            self::assertNotSame('', trim((string) $policy['independent_reviewer_role']));
            self::assertNotSame('', trim((string) $policy['frequency']));
            self::assertNotEmpty($policy['required_evidence']);
            self::assertNotEmpty($policy['contributing_domains']);
            self::assertSame('PENDING_OWNER_DESIGNATION', $policy['designation_status']);
            self::assertNull($policy['named_accountable_person']);
            self::assertSame(
                'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED',
                $policy['adapter_activation'],
            );
        }
    }

    #[Test]
    public function unknown_or_blank_account_codes_are_rejected(): void
    {
        $registry = new FinanceControlAccountOwnershipRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->forAccountCode('  ');
    }

    #[Test]
    public function incomplete_or_inconsistent_policy_coverage_is_rejected(): void
    {
        $registry = new FinanceControlAccountOwnershipRegistry();
        $chart = require base_path('arna_core/src/Domains/Finance/Config/chart_of_accounts_v1.php');
        $chart[] = $chart[0];

        $this->expectException(LogicException::class);
        $registry->validateAgainstChart($chart);
    }
}
