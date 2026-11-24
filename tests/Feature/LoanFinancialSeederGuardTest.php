<?php

declare(strict_types=1);

namespace Tests\Feature;

use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanProductRulesPolicySeeder;
use MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanProductsSeeder;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

#[CoversNothing]
final class LoanFinancialSeederGuardTest extends TestCase
{
    public function test_loan_products_seeder_refuses_to_create_hard_coded_products(): void
    {
        $this->expectException(LogicException::class);
        (new LoanProductsSeeder())->run();
    }

    public function test_loan_policy_seeder_refuses_to_publish_hard_coded_financial_terms(): void
    {
        $this->expectException(LogicException::class);
        (new LoanProductRulesPolicySeeder())->run();
    }
}
