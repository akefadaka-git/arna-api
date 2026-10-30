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
        $chart = config('finance.chart_of_accounts_v1', []);
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
            $this->assertSame('PENDING_OWNER_DESIGNATION', $policy['designation_status']);
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
        $chart = config('finance.chart_of_accounts_v1', []);
        $controlAccount = null;
        foreach ($chart as $row) {
            if (($row[7] ?? false) === true) {
                $controlAccount = $row;
                break;
            }
        }
        $this->assertNotNull($controlAccount);
        $chart[] = $controlAccount;

        $this->expectException(LogicException::class);
        $registry->validateAgainstChart($chart);
    }

    #[Test]
    public function account_metadata_must_match_the_canonical_chart(): void
    {
        $policies = config('finance.control_account_ownership_v1');
        $policies['1110']['account_name_en'] = 'Different account';
        config(['finance.control_account_ownership_v1' => $policies]);

        $this->expectException(LogicException::class);
        (new ControlAccountOwnershipRegistry())->validateAgainstChart(config('finance.chart_of_accounts_v1'));
    }

    #[Test]
    public function accountable_and_independent_reviewer_roles_must_be_distinct(): void
    {
        $policies = config('finance.control_account_ownership_v1');
        $policies['1110']['independent_reviewer_role'] = $policies['1110']['accountable_role'];
        config(['finance.control_account_ownership_v1' => $policies]);

        $this->expectException(LogicException::class);
        (new ControlAccountOwnershipRegistry())->validateAgainstChart(config('finance.chart_of_accounts_v1'));
    }

    #[Test]
    public function pending_designation_cannot_name_a_person_or_enable_an_adapter(): void
    {
        $policies = config('finance.control_account_ownership_v1');
        $policies['1110']['named_accountable_person'] = 'Not approved';
        config(['finance.control_account_ownership_v1' => $policies]);

        $this->expectException(LogicException::class);
        (new ControlAccountOwnershipRegistry())->validateAgainstChart(config('finance.chart_of_accounts_v1'));
    }

    #[Test]
    public function unapproved_designation_status_is_rejected(): void
    {
        $policies = config('finance.control_account_ownership_v1');
        $policies['1110']['designation_status'] = 'OWNER_DESIGNATED';
        config(['finance.control_account_ownership_v1' => $policies]);

        $this->expectException(LogicException::class);
        (new ControlAccountOwnershipRegistry())->validateAgainstChart(config('finance.chart_of_accounts_v1'));
    }

}
