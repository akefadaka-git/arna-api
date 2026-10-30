<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Services\SubledgerPostingInstruction;

interface SubledgerPostingContract
{
    public function supports(JournalSourceType $sourceType): bool;

    /**
     * Resolve one authoritative source event. The returned instruction must
     * carry the immutable event ID and event version from the owning domain's
     * event/transaction record; aggregate IDs and mutable updated_at values
     * are not substitutes for source-event identity.
     */
    public function resolvePosting(int $tenantId, int $legalEntityId, string $sourceId): SubledgerPostingInstruction;
}
