<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

abstract class FinanceLifecycleEvent
{
    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $legalEntityId,
        public readonly int $actorId,
        public readonly string $eventType,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?array $beforeState,
        public readonly ?array $afterState,
        public readonly array $metadata = [],
    ) {}

    public function auditPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId, 'legal_entity_id' => $this->legalEntityId,
            'actor_id' => $this->actorId, 'event_type' => $this->eventType,
            'subject_type' => $this->subjectType, 'subject_id' => $this->subjectId,
            'before_state' => $this->beforeState, 'after_state' => $this->afterState,
            'metadata' => $this->metadata, 'occurred_at' => now(),
        ];
    }
}
