<?php

declare(strict_types=1);

namespace Tests\Unit\Governance;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionRegisterService;
use Tests\TestCase;

final class GovernanceElectionRegisterServiceTest extends TestCase
{
    public function test_default_entitlement_is_proportional_to_eligible_shares(): void
    {
        self::assertSame('125.0000', GovernanceElectionRegisterService::defaultVotingWeight('125'));
        self::assertSame('250.0000', GovernanceElectionRegisterService::defaultVotingWeight('125', '2'));
    }

    public function test_fractional_share_quantity_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::defaultVotingWeight('10.5');
    }

    public function test_zero_or_negative_class_rate_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        GovernanceElectionRegisterService::defaultVotingWeight('10', '0');
    }
}
