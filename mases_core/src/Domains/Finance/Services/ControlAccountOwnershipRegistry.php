<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use InvalidArgumentException;
use LogicException;

/**
 * Read-only registry for the CoA v1 control-account accountability baseline.
 *
 * Defines functional roles and evidence requirements only. It does not appoint
 * personnel, grant permissions, enable adapters, or post reconciliation results.
 */
final class ControlAccountOwnershipRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $policies = config('finance.control_account_ownership_v1', []);
        if (!is_array($policies)) {
            throw new LogicException('Finance control-account ownership configuration must return an array.');
        }

        return $policies;
    }

    /** @return array<string, mixed> */
    public function forAccountCode(string $code): array
    {
        $normalized = trim($code);
        if ($normalized === '') {
            throw new InvalidArgumentException('A non-empty account code is required.');
        }

        $policy = $this->all()[$normalized] ?? null;
        if (!is_array($policy)) {
            throw new InvalidArgumentException(
                "No control-account ownership policy is configured for account {$normalized}."
            );
        }

        return $policy;
    }

    /**
     * Ensure every canonical control account has one fail-closed policy.
     *
     * @param list<array<int, mixed>> $chart
     */
    public function validateAgainstChart(array $chart): void
    {
        $controlCodes = [];
        foreach ($chart as $row) {
            if (!is_array($row) || !array_key_exists(0, $row) || !array_key_exists(7, $row)) {
                throw new LogicException('Canonical chart contains a malformed row; ownership validation cannot continue.');
            }

            if ($row[7] === true) {
                $controlCodes[] = (string) $row[0];
            }
        }

        if (count($controlCodes) !== count(array_unique($controlCodes))) {
            throw new LogicException('Canonical chart contains duplicate control-account codes.');
        }

        $policies = $this->all();
        $policyCodes = array_map('strval', array_keys($policies));
        $missing = array_values(array_diff($controlCodes, $policyCodes));
        $unexpected = array_values(array_diff($policyCodes, $controlCodes));

        if ($missing !== [] || $unexpected !== []) {
            throw new LogicException(
                'Control-account ownership register does not match the canonical chart. Missing: '
                . implode(', ', $missing) . '; unexpected: ' . implode(', ', $unexpected) . '.'
            );
        }

        foreach ($policies as $code => $policy) {
            if (!is_array($policy)) {
                throw new LogicException("Control account {$code} has a malformed ownership policy.");
            }

            foreach ([
                'account_name_en',
                'account_type',
                'accountable_role',
                'frequency',
                'independent_reviewer_role',
                'designation_status',
                'adapter_activation',
            ] as $field) {
                if (!isset($policy[$field]) || trim((string) $policy[$field]) === '') {
                    throw new LogicException("Control account {$code} is missing required field {$field}.");
                }
            }

            foreach (['contributing_domains', 'required_evidence'] as $field) {
                if (!isset($policy[$field]) || !is_array($policy[$field]) || $policy[$field] === []) {
                    throw new LogicException("Control account {$code} requires a non-empty {$field} list.");
                }
                foreach ($policy[$field] as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        throw new LogicException("Control account {$code} has an invalid {$field} item.");
                    }
                }
            }

            if (($policy['designation_status'] ?? null) === 'PENDING_OWNER_DESIGNATION') {
                if (($policy['named_accountable_person'] ?? null) !== null) {
                    throw new LogicException("Control account {$code} cannot have a named owner while designation is pending.");
                }
                if (($policy['adapter_activation'] ?? null) !== 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED') {
                    throw new LogicException("Control account {$code} must remain blocked while owner designation is pending.");
                }
            }
        }
    }
}
