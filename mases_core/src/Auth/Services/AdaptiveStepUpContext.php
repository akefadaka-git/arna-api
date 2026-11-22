<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Http\Request;
use JsonException;

final class AdaptiveStepUpContext
{
    private const FIELD_ALLOWLISTS = [
        'wallet.payment.record' => ['wallet_id', 'amount', 'received_at', 'reference_type', 'reference_id', 'description'],
        'loan.repayment.record' => ['loan_id', 'amount', 'received_at', 'reference_type', 'reference_id', 'description', 'allocations'],
        'identity_change' => ['session_id', 'identity_type', 'identity_id', 'new_value_hash'],
    ];

    public function fingerprint(string $action, array $context): string
    {
        $allowed = self::FIELD_ALLOWLISTS[$action] ?? null;
        if ($allowed !== null) {
            $context = array_intersect_key($context, array_flip($allowed));
        }

        $normalized = $this->normalize($context);

        try {
            return hash('sha256', json_encode(
                ['action' => $action, 'context' => $normalized],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Step-up context is not serializable.', previous: $exception);
        }
    }

    public function fromRequest(string $action, Request $request): array
    {
        $context = $request->except([
            'step_up_token',
            'challenge_token',
            'otp_code',
            'code',
            'knowledge_proof',
            'password',
            'pin',
            'current_password',
            '_token',
        ]);

        $wallet = $request->route('wallet');
        if ($wallet !== null) {
            $context['wallet_id'] = is_object($wallet) && method_exists($wallet, 'getKey')
                ? (string) $wallet->getKey()
                : (string) $wallet;
        }

        $loan = $request->route('loan');
        if ($loan !== null) {
            $context['loan_id'] = is_object($loan) && method_exists($loan, 'getKey')
                ? (string) $loan->getKey()
                : (string) $loan;
        }

        $allocation = $request->route('allocation');
        if ($allocation !== null) {
            $context['allocation_id'] = is_object($allocation) && method_exists($allocation, 'getKey')
                ? (string) $allocation->getKey()
                : (string) $allocation;
        }

        return $context;
    }

    public function supportsAction(string $action): bool
    {
        return in_array($action, [
            'password_recovery',
            'password_reset',
            'identity_change',
            'classified_action',
            'sensitive_transaction',
            'wallet.payment.record',
            'loan.repayment.record',
            'wallet.balance.adjust',
            'wallet.payment.reverse',
            'loan.approve',
            'loan.restructure',
            'loan.disburse',
            'loan.writeoff',
            'critical_financial_transaction',
            'critical_admin_action',
            'wallet_ownership_transfer',
        ], true);
    }

    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            } elseif (str_ends_with((string) $key, '_id') && is_scalar($item)) {
                $value[$key] = (string) $item;
            } elseif (is_object($item)) {
                $value[$key] = method_exists($item, 'toArray') ? $this->normalize($item->toArray()) : (string) $item;
            } elseif (is_float($item)) {
                // Preserve the exact client-supplied decimal representation for money.
                $value[$key] = (string) $item;
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
