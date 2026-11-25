<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use InvalidArgumentException;

final class ProviderApprovalPolicy
{
    public const VERSION = '1.0.0';

    /**
     * @return array{required_approvals: int, owner_signature_required: bool}
     */
    public function forRiskTier(string $riskTier): array
    {
        return match ($riskTier) {
            'low_reversible', 'elevated' => [
                'required_approvals' => 2,
                'owner_signature_required' => false,
            ],
            'critical' => [
                'required_approvals' => 2,
                'owner_signature_required' => true,
            ],
            default => throw new InvalidArgumentException('Unknown provider operation risk tier.'),
        };
    }
}
