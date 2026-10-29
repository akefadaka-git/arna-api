<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use InvalidArgumentException;
use LogicException;

final class ControlAccountOwnershipRegistry
{
    /** @return array<string, array{accountable_role:string, preparer_role:string, reviewer_role:string, frequency:string, evidence:list<string>, escalation_role:string}> */
    public function all(): array
    {
        /** @var array<string, array{accountable_role:string, preparer_role:string, reviewer_role:string, frequency:string, evidence:list<string>, escalation_role:string}> $policies */
        $policies = require base_path('config/finance/control_account_ownership_v1.php');

        return $policies;
    }

    /** @return array{accountable_role:string, preparer_role:string, reviewer_role:string, frequency:string, evidence:list<string>, escalation_role:string} */
    public function forAccountCode(string $code): array
    {
        $normalized = strtoupper(trim($code));
        $policy = $this->all()[$normalized] ?? null;

        if (!is_array($policy)) {
            throw new InvalidArgumentException("No control-account ownership policy is configured for account {$normalized}.");
        }

        return $policy;
    }

    /**
     * Verify that every canonical control account has exactly one policy and
     * that no policy points to a non-control or unknown account.
     *
     * @param list<array<int, mixed>> $chart
     */
    public function validateAgainstChart(array $chart): void
    {
        $controlCodes = [];
        foreach ($chart as $row) {
            if (!is_array($row) || !isset($row[0])) {
                throw new LogicException('Canonical chart contains a malformed row; ownership validation cannot continue.');
            }

            if (($row[7] ?? false) === true) {
                $controlCodes[] = (string) $row[0];
            }
        }

        $policies = $this->all();
        $missing = array_values(array_diff($controlCodes, array_keys($policies)));
        $unexpected = array_values(array_diff(array_keys($policies), $controlCodes));

        if ($missing !== [] || $unexpected !== []) {
            throw new LogicException(
                'Control-account ownership register does not match the canonical chart. Missing: '
                . implode(', ', $missing) . '; unexpected: ' . implode(', ', $unexpected) . '.'
            );
        }

        foreach ($policies as $code => $policy) {
            foreach (['accountable_role', 'preparer_role', 'reviewer_role', 'frequency', 'escalation_role'] as $required) {
                if (!isset($policy[$required]) || trim((string) $policy[$required]) === '') {
                    throw new LogicException("Control account {$code} is missing required ownership field {$required}.");
                }
            }

            if (($policy['preparer_role'] ?? null) === ($policy['reviewer_role'] ?? null)) {
                throw new LogicException("Control account {$code} must separate preparer and reviewer roles.");
            }

            if (empty($policy['evidence']) || !is_array($policy['evidence'])) {
                throw new LogicException("Control account {$code} requires at least one evidence item.");
            }
        }
    }
}
