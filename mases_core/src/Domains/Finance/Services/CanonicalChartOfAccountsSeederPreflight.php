<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use LogicException;

/**
 * Pure preflight for canonical CoA seeding.
 *
 * All legal entities must pass before the seeder performs its first write.
 */
final class CanonicalChartOfAccountsSeederPreflight
{
    /**
     * @param list<array<int, mixed>> $register
     * @param list<object> $entities
     * @param array<string, list<object>> $existingByEntity
     */
    public function validate(array $register, array $entities, array $existingByEntity): void
    {
        foreach ($entities as $entity) {
            $entityId = (string) ($entity->id ?? '');
            $tenantId = (string) ($entity->tenant_id ?? '');
            if ($entityId === '' || $tenantId === '') {
                throw new LogicException('Every active legal entity must have an ID and tenant ID before CoA seeding.');
            }

            $rows = $existingByEntity[$entityId] ?? null;
            if (!is_array($rows)) {
                throw new LogicException("CoA preflight is missing existing-account data for legal entity {$entityId}.");
            }

            $byCode = [];
            foreach ($rows as $row) {
                if (!is_object($row)) {
                    throw new LogicException("Existing ledger-account data for legal entity {$entityId} is malformed.");
                }

                $code = $row->code ?? null;
                if (!is_string($code) || $code === '') {
                    throw new LogicException("Existing ledger account for legal entity {$entityId} has an invalid code.");
                }
                if ((string) ($row->tenant_id ?? '') !== $tenantId
                    || (string) ($row->legal_entity_id ?? '') !== $entityId) {
                    throw new LogicException("Existing ledger account {$code} crosses its legal-entity or tenant boundary.");
                }

                $byCode[$code][] = $row;
            }

            foreach ($register as $canonicalRow) {
                if (!is_array($canonicalRow) || !is_string($canonicalRow[0] ?? null)) {
                    throw new LogicException('Canonical CoA register contains a malformed account row.');
                }

                $code = $canonicalRow[0];
                $matches = $byCode[$code] ?? [];
                if (count($matches) > 1) {
                    throw new LogicException(
                        "Multiple ledger-account rows already use canonical CoA code {$code} for legal entity {$entityId}."
                    );
                }

                $existing = $matches[0] ?? null;
                if ($existing !== null && !(bool) ($existing->is_system_account ?? false)) {
                    throw new LogicException(
                        "CoA code {$code} already exists as a tenant-owned non-system account for legal entity {$entityId}; refusing overwrite."
                    );
                }
            }
        }
    }
}
