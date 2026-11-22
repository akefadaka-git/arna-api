<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

/**
 * Central policy catalogue for ARNA adaptive authentication.
 *
 * This class is deliberately side-effect free. Controllers and domain
 * workflows must call it before authorizing an operation; it does not
 * itself verify credentials, issue sessions, or grant permissions.
 */
final class AdaptiveAuthenticationPolicy
{
    public const PASSWORD = 'password';
    public const PIN = 'pin';
    public const OTP = 'otp';
    public const GOOGLE_AUTHENTICATOR = 'google_authenticator';

    public const STANDARD = 'standard';
    public const ENHANCED = 'enhanced';
    public const CRITICAL = 'critical';

    private const ENHANCED_ACTIONS = [
        'wallet.payment.record',
        'loan.repayment.record',
        'password_recovery',
        'password_reset',
        'identity_change',
        'classified_action',
        'sensitive_transaction',
    ];

    private const CRITICAL_ACTIONS = [
        'critical_financial_transaction',
        'wallet_ownership_transfer',
        'privilege_escalation',
        'critical_admin_action',
    ];

    /**
     * Return the authentication requirements for an operation.
     *
     * The six ARNA identifiers are deliberately independent of this policy:
     * identifier resolution selects the account, while this policy governs
     * proof of control over that account.
     *
     * @return array{
     *   risk_level:string,
     *   required_factor_count:int,
     *   allowed_authenticators:list<string>,
     *   required_factor_groups:list<list<string>>,
     *   step_up_required:bool,
     *   recognized_device_required_for_pin:bool
     * }
     */
    public function requirementsFor(
        string $action,
        bool $firstLogin = false,
        bool $recognizedDevice = false,
    ): array {
        $action = strtolower(trim($action));

        if ($firstLogin && in_array($action, ['login', 'session_resume'], true)) {
            return $this->singleFactor(
                self::STANDARD,
                [self::PASSWORD],
                false,
            );
        }

        if (in_array($action, self::CRITICAL_ACTIONS, true) || ! $this->isKnownAction($action)) {
            $knowledgeFactors = [self::PASSWORD];

            if ($recognizedDevice) {
                $knowledgeFactors[] = self::PIN;
            }

            return [
                'risk_level' => self::CRITICAL,
                'required_factor_count' => 2,
                'allowed_authenticators' => array_values(array_unique([
                    ...$knowledgeFactors,
                    self::OTP,
                    self::GOOGLE_AUTHENTICATOR,
                ])),
                'required_factor_groups' => [
                    $knowledgeFactors,
                    [self::OTP, self::GOOGLE_AUTHENTICATOR],
                ],
                'step_up_required' => true,
                'recognized_device_required_for_pin' => true,
            ];
        }

        if (in_array($action, self::ENHANCED_ACTIONS, true)) {
            return $this->singleFactor(
                self::ENHANCED,
                [self::OTP, self::GOOGLE_AUTHENTICATOR],
                true,
            );
        }

        $authenticators = [self::PASSWORD, self::OTP, self::GOOGLE_AUTHENTICATOR];

        if ($recognizedDevice) {
            $authenticators[] = self::PIN;
        }

        return $this->singleFactor(self::STANDARD, $authenticators, false);
    }

    /**
     * Whether one authenticator is an eligible member of the returned policy.
     *
     * Critical operations still require successful proof from both distinct
     * factor groups; this method only checks eligibility of one submitted factor.
     */
    public function acceptsAuthenticator(
        string $authenticator,
        string $action,
        bool $firstLogin = false,
        bool $recognizedDevice = false,
    ): bool {
        $authenticator = strtolower(trim($authenticator));
        $requirements = $this->requirementsFor($action, $firstLogin, $recognizedDevice);

        return in_array($authenticator, $requirements['allowed_authenticators'], true);
    }

    /**
     * @param list<string> $authenticators
     * @return array{
     *   risk_level:string,
     *   required_factor_count:int,
     *   allowed_authenticators:list<string>,
     *   required_factor_groups:list<list<string>>,
     *   step_up_required:bool,
     *   recognized_device_required_for_pin:bool
     * }
     */
    private function singleFactor(string $riskLevel, array $authenticators, bool $stepUp): array
    {
        return [
            'risk_level' => $riskLevel,
            'required_factor_count' => 1,
            'allowed_authenticators' => array_values(array_unique($authenticators)),
            'required_factor_groups' => [$authenticators],
            'step_up_required' => $stepUp,
            'recognized_device_required_for_pin' => true,
        ];
    }

    private function isKnownAction(string $action): bool
    {
        return in_array($action, [
            'login',
            'session_resume',
            ...self::ENHANCED_ACTIONS,
            ...self::CRITICAL_ACTIONS,
        ], true);
    }
}
