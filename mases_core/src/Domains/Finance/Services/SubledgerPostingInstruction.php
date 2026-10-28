<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

final class SubledgerPostingInstruction
{
    public function __construct(
        public readonly string $journalNumber,
        public readonly string $effectiveDate,
        public readonly string $description,
        public readonly string $idempotencyKey,
        public readonly array $lines,
        public readonly string $currency = 'ETB',
    ) {
        if (trim($journalNumber)==='' || trim($description)==='' || trim($idempotencyKey)==='' || count($lines)<2) {
            throw new \InvalidArgumentException('Subledger instructions require a journal number, description, idempotency key, and at least two lines.');
        }
    }

    public function toHeader(int $entityId, string $sourceType, string $sourceId): array
    {
        return ['legal_entity_id'=>$entityId,'journal_number'=>$this->journalNumber,'effective_date'=>$this->effectiveDate,
            'description'=>$this->description,'idempotency_key'=>$this->idempotencyKey,'currency'=>$this->currency,
            'source_type'=>$sourceType,'source_id'=>$sourceId];
    }
}
