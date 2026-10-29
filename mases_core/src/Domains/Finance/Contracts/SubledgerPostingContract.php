<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Contracts;

use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Services\SubledgerPostingInstruction;

interface SubledgerPostingContract
{
    public function supports(JournalSourceType $sourceType): bool;

    public function resolvePosting(int $tenantId, int $legalEntityId, string $sourceId): SubledgerPostingInstruction;
}
