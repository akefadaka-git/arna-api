<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use LogicException;
use MaaleArna\MASES\Core\Domains\Finance\Services\CanonicalChartOfAccountsValidator;
use PHPUnit\Framework\TestCase;

final class CanonicalChartOfAccountsIntegrityTest extends TestCase
{
    private function canonicalRegister(): array
    {
        return require dirname(__DIR__, 2) . '/Config/chart_of_accounts_v1.php';
    }

    public function test_canonical_register_has_79_structurally_valid_accounts(): void
    {
        $register = $this->canonicalRegister();

        self::assertCount(79, $register);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_normal_balance_inconsistent_with_account_type(): void
    {
        $register = $this->canonicalRegister();
        foreach ($register as &$row) {
            if ($row[0] === '4100') {
                $row[5] = 'debit';
                break;
            }
        }
        unset($row);

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_changes_to_explicit_contra_balance_exceptions(): void
    {
        $register = $this->canonicalRegister();
        foreach ($register as &$row) {
            if ($row[0] === '1220') {
                $row[5] = 'debit';
                break;
            }
        }
        unset($row);

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_posting_parent_accounts(): void
    {
        $register = $this->canonicalRegister();
        foreach ($register as &$row) {
            if ($row[0] === '1100') {
                $row[6] = true;
                break;
            }
        }
        unset($row);

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_parent_child_account_type_mismatch(): void
    {
        $register = $this->canonicalRegister();
        foreach ($register as &$row) {
            if ($row[0] === '1110') {
                $row[1] = '2000';
                break;
            }
        }
        unset($row);

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_control_account_that_is_not_postable(): void
    {
        $register = $this->canonicalRegister();
        foreach ($register as &$row) {
            if ($row[0] === '1110') {
                $row[6] = false;
                break;
            }
        }
        unset($row);

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_duplicate_account_codes(): void
    {
        $register = $this->canonicalRegister();
        $register[1][0] = $register[0][0];

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }

    public function test_rejects_missing_canonical_account_rows(): void
    {
        $register = $this->canonicalRegister();
        array_pop($register);

        $this->expectException(LogicException::class);
        (new CanonicalChartOfAccountsValidator())->validate($register);
    }
}
