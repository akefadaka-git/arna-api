<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\MASES\Core\Auth\Services\AdaptiveAuthenticationPolicy;
use PHPUnit\Framework\TestCase;

final class AdaptiveAuthenticationPolicyTest extends TestCase
{
    private AdaptiveAuthenticationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AdaptiveAuthenticationPolicy();
    }

    public function test_first_login_requires_password_even_on_a_recognized_device(): void
    {
        $requirements = $this->policy->requirementsFor('login', firstLogin: true, recognizedDevice: true);

        self::assertSame(AdaptiveAuthenticationPolicy::STANDARD, $requirements['risk_level']);
        self::assertSame([AdaptiveAuthenticationPolicy::PASSWORD], $requirements['allowed_authenticators']);
        self::assertSame(1, $requirements['required_factor_count']);
    }

    public function test_standard_login_allows_any_single_authenticator_but_pin_requires_recognized_device(): void
    {
        $unknownDevice = $this->policy->requirementsFor('login');
        $recognizedDevice = $this->policy->requirementsFor('login', recognizedDevice: true);

        self::assertSame([
            AdaptiveAuthenticationPolicy::PASSWORD,
            AdaptiveAuthenticationPolicy::OTP,
            AdaptiveAuthenticationPolicy::GOOGLE_AUTHENTICATOR,
        ], $unknownDevice['allowed_authenticators']);

        self::assertContains(AdaptiveAuthenticationPolicy::PIN, $recognizedDevice['allowed_authenticators']);
        self::assertFalse($this->policy->acceptsAuthenticator(AdaptiveAuthenticationPolicy::PIN, 'login'));
        self::assertTrue($this->policy->acceptsAuthenticator(AdaptiveAuthenticationPolicy::PIN, 'login', recognizedDevice: true));
    }

    public function test_recovery_and_identity_changes_require_otp_or_google_authenticator(): void
    {
        foreach (['password_recovery', 'password_reset', 'identity_change'] as $action) {
            $requirements = $this->policy->requirementsFor($action);

            self::assertSame(AdaptiveAuthenticationPolicy::ENHANCED, $requirements['risk_level']);
            self::assertSame(1, $requirements['required_factor_count']);
            self::assertSame([
                AdaptiveAuthenticationPolicy::OTP,
                AdaptiveAuthenticationPolicy::GOOGLE_AUTHENTICATOR,
            ], $requirements['allowed_authenticators']);
            self::assertTrue($requirements['step_up_required']);
        }
    }

    public function test_critical_operations_require_two_distinct_factor_groups(): void
    {
        $requirements = $this->policy->requirementsFor('critical_financial_transaction');

        self::assertSame(AdaptiveAuthenticationPolicy::CRITICAL, $requirements['risk_level']);
        self::assertSame(2, $requirements['required_factor_count']);
        self::assertSame([
            [AdaptiveAuthenticationPolicy::PASSWORD],
            [AdaptiveAuthenticationPolicy::OTP, AdaptiveAuthenticationPolicy::GOOGLE_AUTHENTICATOR],
        ], $requirements['required_factor_groups']);
    }

    public function test_recognized_device_pin_can_be_the_knowledge_factor_for_critical_action(): void
    {
        $requirements = $this->policy->requirementsFor(
            'wallet_ownership_transfer',
            recognizedDevice: true,
        );

        self::assertSame([
            AdaptiveAuthenticationPolicy::PASSWORD,
            AdaptiveAuthenticationPolicy::PIN,
        ], $requirements['required_factor_groups'][0]);
    }

    public function test_first_login_password_rule_does_not_bypass_critical_action_step_up(): void
    {
        $requirements = $this->policy->requirementsFor(
            'critical_financial_transaction',
            firstLogin: true,
        );

        self::assertSame(AdaptiveAuthenticationPolicy::CRITICAL, $requirements['risk_level']);
        self::assertSame(2, $requirements['required_factor_count']);
        self::assertSame([
            [AdaptiveAuthenticationPolicy::PASSWORD],
            [AdaptiveAuthenticationPolicy::OTP, AdaptiveAuthenticationPolicy::GOOGLE_AUTHENTICATOR],
        ], $requirements['required_factor_groups']);
    }

    public function test_unclassified_actions_fail_closed_at_critical_level(): void
    {
        $requirements = $this->policy->requirementsFor('unknown_sensitive_operation');

        self::assertSame(AdaptiveAuthenticationPolicy::CRITICAL, $requirements['risk_level']);
        self::assertSame(2, $requirements['required_factor_count']);
    }
}
