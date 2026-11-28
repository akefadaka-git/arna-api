<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Services;

use InvalidArgumentException;
use LogicException;

/**
 * Fail-closed policy registry for canonical control accounts.
 *
 * It proposes role-level accountability and evidence cadence. It does not
 * appoint people, grant permissions, approve account mappings or activate
 * source adapters.
 */
final class FinanceControlAccountOwnershipRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $policies;

    /** @param array<string, array<string, mixed>>|null $policies */
    public function __construct(?array $policies = null)
    {
        $this->policies = $policies ?? require dirname(__DIR__) . '/Config/control_account_ownership_v1.php';
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->policies;
    }

    /** @return array<string, mixed> */
    public function forAccountCode(string $code): array
    {
        $code = trim($code);
        if ($code === '' || ! isset($this->policies[$code])) {
            throw new InvalidArgumentException('Unknown or blank Finance control-account code.');
        }

        return $this->policies[$code];
    }

    /**
     * @param list<array<int, mixed>> $chart
     */
    public function validateAgainstChart(array $chart): void
    {
        $controlCodes = [];
        foreach ($chart as $row) {
            if (($row[7] ?? false) === true) {
                $controlCodes[] = (string) ($row[0] ?? '');
            }
        }

        if (count($controlCodes) !== count(array_unique($controlCodes))) {
            throw new LogicException('The canonical chart contains duplicate control-account codes.');
        }

        $configuredCodes = array_keys($this->policies);
        sort($controlCodes);
        sort($configuredCodes);

        if ($controlCodes !== $configuredCodes) {
            throw new LogicException('Control-account ownership policy must cover exactly the canonical control accounts.');
        }

        foreach ($this->policies as $code => $policy) {
            foreach (['accountable_role', 'independent_reviewer_role', 'frequency'] as $required) {
                if (trim((string) ($policy[$required] ?? '')) === '') {
                    throw new LogicException("Control account {$code} has an incomplete ownership policy.");
                }
            }

            if (empty($policy['required_evidence']) || empty($policy['contributing_domains'])) {
                throw new LogicException("Control account {$code} must define evidence and contributing domains.");
            }

            if (($policy['designation_status'] ?? null) !== 'PENDING_OWNER_DESIGNATION'
                || ($policy['named_accountable_person'] ?? 'not-null') !== null
                || ($policy['adapter_activation'] ?? null) !== 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED') {
                throw new LogicException("Control account {$code} must remain fail-closed until Owner designation.");
            }
        }
    }
}
