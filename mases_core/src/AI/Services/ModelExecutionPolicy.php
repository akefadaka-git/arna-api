<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\AI\Services;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Central execution kill-switch and request bounds.
 * This policy does not enable a provider; it is an additional guard.
 */
final class ModelExecutionPolicy
{
    public function assertAllowed(int $inputTokens, int $outputTokens, float $estimatedCostEtb): void
    {
        if (!(bool) config('arna_ai.execution.enabled', false)
            || !(bool) config('arna_ai.provider.enabled', false)
            || !(bool) config('arna_ai.provider.egress_enabled', false)) {
            throw new ServiceUnavailableHttpException(null, 'ARNA AI model execution is disabled.');
        }

        if ($inputTokens < 0 || $outputTokens < 0 || $estimatedCostEtb < 0) {
            throw new InvalidArgumentException('AI usage estimates cannot be negative.');
        }

        if ($inputTokens > (int) config('arna_ai.execution.max_input_tokens', 2048)
            || $outputTokens > (int) config('arna_ai.execution.max_output_tokens', 2048)) {
            throw new ServiceUnavailableHttpException(null, 'The AI request exceeds the configured token limits.');
        }

        $budget = (float) config('arna_ai.execution.monthly_budget_etb', 0);
        if ($budget <= 0 || $estimatedCostEtb > $budget) {
            throw new ServiceUnavailableHttpException(null, 'The ARNA AI monthly cost budget is not authorized or has been exceeded.');
        }
    }
}
