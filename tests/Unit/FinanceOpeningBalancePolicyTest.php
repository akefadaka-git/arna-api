<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceOpeningBalancePolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceOpeningBalancePolicyTest extends TestCase
{
    #[Test]
    public function opening_balance_import_is_disabled_and_requires_a_complete_evidence_package(): void
    {
        $policy = app(FinanceOpeningBalancePolicy::class);
        $definition = $policy->policy();

        self::assertSame('disabled', $definition['activation_status']);
        self::assertTrue($definition['owner_approval_required']);
        self::assertContains('signed_source_trial_balance', $definition['required_evidence']);
        self::assertContains('balanced_debit_credit_batch', $definition['required_controls']);
        self::assertContains('direct_account_balance_mutation', $definition['prohibited_actions']);

        $this->expectException(ValidationException::class);
        $policy->assertImportAllowed();
    }
}
