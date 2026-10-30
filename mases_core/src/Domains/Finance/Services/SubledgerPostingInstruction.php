<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

/**
 * Immutable accounting instruction resolved from one authoritative source event.
 * The source event version is part of the idempotent identity, not a display field.
 */
final class SubledgerPostingInstruction
{
    public function __construct(
        public readonly string $journalNumber,
        public readonly string $effectiveDate,
        public readonly string $description,
        public readonly string $idempotencyKey,
        public readonly string $sourceEventId,
        public readonly string $sourceEventVersion,
        public readonly array $lines,
        public readonly string $currency = 'ETB',
    ) {
        if (
            trim($journalNumber) === ''
            || trim($description) === ''
            || trim($idempotencyKey) === ''
            || trim($sourceEventId) === ''
            || mb_strlen($sourceEventId) > 128
            || trim($sourceEventVersion) === ''
            || mb_strlen($sourceEventVersion) > 64
            || count($lines) < 2
        ) {
            throw new \InvalidArgumentException(
                'Subledger instructions require a journal number, description, idempotency key, source event ID, source event version, and at least two lines.'
            );
        }
    }

    public function toHeader(int $entityId, string $sourceType, string $sourceId): array
    {
        return [
            'legal_entity_id' => $entityId,
            'journal_number' => $this->journalNumber,
            'effective_date' => $this->effectiveDate,
            'description' => $this->description,
            'idempotency_key' => $this->idempotencyKey,
            'currency' => $this->currency,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_event_id' => $this->sourceEventId,
            'source_event_version' => $this->sourceEventVersion,
        ];
    }
}
