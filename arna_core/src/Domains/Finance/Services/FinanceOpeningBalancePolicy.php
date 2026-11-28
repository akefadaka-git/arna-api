<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Services;

use Illuminate\Validation\ValidationException;

final class FinanceOpeningBalancePolicy
{
    /** @return array<string, mixed> */
    public function policy(): array
    {
        return require dirname(__DIR__) . '/Config/opening_balance_policy_v1.php';
    }

    /**
     * This guard is intentionally fail-closed. Phase 01 defines governance;
     * it does not implement or enable an opening-balance import operation.
     */
    public function assertImportAllowed(): void
    {
        $policy = $this->policy();

        if (($policy['activation_status'] ?? 'disabled') !== 'enabled'
            || ($policy['owner_approval_required'] ?? true) !== false) {
            throw ValidationException::withMessages([
                'opening_balance' => 'Opening-balance import is disabled pending explicit Owner approval and cutover evidence.',
            ]);
        }
    }
}
