<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Contracts;

use MaaleArna\Arna\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\Arna\Core\Domains\Finance\Services\SubledgerPostingInstruction;

interface SubledgerPostingContract
{
    public function supports(JournalSourceType $sourceType): bool;

    public function resolvePosting(int $tenantId, int $legalEntityId, string $sourceId): SubledgerPostingInstruction;
}
