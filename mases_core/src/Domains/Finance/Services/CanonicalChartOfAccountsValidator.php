<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use LogicException;

/**
 * Structural validator for canonical Finance CoA v1.0.0.
 *
 * This validates accounting structure only; it does not approve accounting
 * policy, tax treatment, control-account personnel, or production activation.
 */
final class CanonicalChartOfAccountsValidator
{
    public const EXPECTED_ACCOUNT_COUNT = 79;

    /** Contra-balance accounts are explicit exceptions to their type's usual normal balance. */
    private const CONTRA_NORMAL_BALANCES = [
        '1220' => ['type' => 'asset', 'normal' => 'credit'],
        '1410' => ['type' => 'asset', 'normal' => 'credit'],
        '3600' => ['type' => 'equity', 'normal' => 'debit'],
    ];

    private const TYPE_NORMAL_BALANCE = [
        'asset' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'revenue' => 'credit',
        'expense' => 'debit',
    ];

    private const TAX_CATEGORIES = [
        'taxable', 'non_taxable', 'exempt', 'zero_rated', 'out_of_scope', 'undetermined',
    ];

    /** @param list<array<int, mixed>> $register */
    public function validate(array $register): void
    {
        if (count($register) !== self::EXPECTED_ACCOUNT_COUNT) {
            throw new LogicException(sprintf(
                'Canonical CoA v1.0.0 must contain exactly %d accounts; found %d.',
                self::EXPECTED_ACCOUNT_COUNT,
                count($register)
            ));
        }

        $accounts = [];
        foreach ($register as $row) {
            if (!is_array($row) || count($row) !== 10) {
                throw new LogicException('Every canonical CoA row must contain exactly 10 fields.');
            }

            [$code, $parent, $nameEn, $nameAm, $type, $normal, $posting, $control, $domain, $tax] = $row;

            if (!is_string($code) || !preg_match('/^[0-9]{4}$/', $code) || isset($accounts[$code])) {
                throw new LogicException('Canonical CoA contains an invalid or duplicate account code.');
            }
            if (!is_string($nameEn) || trim($nameEn) === '' || !is_string($nameAm) || trim($nameAm) === '') {
                throw new LogicException("CoA account {$code} requires non-empty English and Amharic names.");
            }
            if (!is_string($type) || !isset(self::TYPE_NORMAL_BALANCE[$type]) || !is_string($normal)
                || !in_array($normal, ['debit', 'credit'], true)) {
                throw new LogicException("CoA account {$code} has an unsupported account type or normal balance.");
            }
            if (!is_bool($posting) || !is_bool($control)) {
                throw new LogicException("CoA account {$code} must declare boolean posting and control flags.");
            }
            if (!is_string($domain) || trim($domain) === '' || !in_array($tax, self::TAX_CATEGORIES, true)) {
                throw new LogicException("CoA account {$code} has an invalid domain or tax-routing category.");
            }

            $contra = self::CONTRA_NORMAL_BALANCES[$code] ?? null;
            $expectedType = $contra['type'] ?? null;
            $expectedNormal = $contra['normal'] ?? self::TYPE_NORMAL_BALANCE[$type];
            if (($expectedType !== null && $type !== $expectedType) || $normal !== $expectedNormal) {
                throw new LogicException(
                    "CoA account {$code} has an inconsistent account type/normal-balance combination."
                );
            }

            if ($control && !$posting) {
                throw new LogicException("Non-posting CoA account {$code} cannot be a control account.");
            }

            if ($parent !== null) {
                if (!is_string($parent) || !isset($accounts[$parent])) {
                    throw new LogicException("Parent account for {$code} must exist earlier in the canonical register.");
                }
                $parentAccount = $accounts[$parent];
                if ($parentAccount['type'] !== $type) {
                    throw new LogicException("CoA account {$code} and parent {$parent} must have the same account type.");
                }
                if ($parentAccount['posting']) {
                    throw new LogicException("Posting CoA account {$parent} cannot have child account {$code}.");
                }
            }

            $accounts[$code] = ['type' => $type, 'posting' => $posting];
        }

        foreach (self::CONTRA_NORMAL_BALANCES as $code => $contra) {
            if (!isset($accounts[$code])) {
                throw new LogicException("Required contra-balance account {$code} is missing from the canonical CoA.");
            }
        }
    }
}
