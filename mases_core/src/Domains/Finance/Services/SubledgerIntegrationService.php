<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\SubledgerPostingContract;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class SubledgerIntegrationService
{
    private array $adapters = [];

    public function __construct(private readonly JournalEntryService $journals, iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof SubledgerPostingContract) throw new \InvalidArgumentException('Invalid subledger adapter.');
            $this->adapters[] = $adapter;
        }
    }

    public function postSource(JournalSourceType $sourceType, int $tenantId, int $actorId, int $entityId, string $sourceId): JournalEntry
    {
        if (!$sourceType->requiresSourceReference() || trim($sourceId)==='') {
            throw ValidationException::withMessages(['source_type'=>'Only external source types can use subledger posting.']);
        }
        $matches = array_values(array_filter($this->adapters, static fn($adapter) => $adapter->supports($sourceType)));
        if (count($matches)!==1) {
            throw ValidationException::withMessages(['source_type'=>count($matches)===0
                ? 'Subledger posting is disabled until an authoritative adapter is registered.'
                : 'Subledger posting has ambiguous adapters and is disabled.']);
        }
        $sourceId = trim($sourceId);
        $instruction = $matches[0]->resolvePosting($tenantId, $entityId, $sourceId);
        $journal = $this->journals->createDraft($tenantId, $actorId,
            $instruction->toHeader($entityId, $sourceType->value, $sourceId), $instruction->lines);
        if ($journal->status===JournalEntry::POSTED) return $journal;
        if ($journal->status!==JournalEntry::DRAFT) throw new ConflictHttpException('The source key is already bound to a non-postable journal state.');
        return $this->journals->post($tenantId, $actorId, (int)$journal->id);
    }
}
