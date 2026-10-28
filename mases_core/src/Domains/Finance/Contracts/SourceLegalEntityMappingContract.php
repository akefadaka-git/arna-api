<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;

interface SourceLegalEntityMappingContract
{
    /**
     * Resolve an explicitly approved source-record mapping.
     *
     * Implementations must fail closed for missing, pending, revoked, ambiguous,
     * cross-tenant, or non-external source references.
     */
    public function resolve(int $tenantId, JournalSourceType $sourceType, string $sourceId): int;
}
