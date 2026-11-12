<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceInboxEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FinanceSubledgerInboxService
{
    /**
     * Persist a source event without trusting caller-supplied tenant/entity/account
     * identity. Until a source-specific adapter is approved, events are retained
     * in quarantine and never posted or discarded.
     */
    public function receive(
        int $tenantId,
        string $sourceType,
        string $sourceEventId,
        string $sourceVersion,
        string $eventType,
        array $payload,
        ?string $evidenceRef = null,
    ): array {
        try {
            $type = JournalSourceType::from($sourceType);
        } catch (\ValueError) {
            throw ValidationException::withMessages(['source_type' => 'Unsupported Finance source type.']);
        }
        if (!$type->requiresSourceReference()) {
            throw ValidationException::withMessages(['source_type' => 'Only external subledger source types can enter the Finance inbox.']);
        }

        $canonicalPayload = $this->canonicalize($payload);
        $canonicalEnvelope = [
            'source_type' => $sourceType,
            'source_event_id' => $sourceEventId,
            'source_version' => $sourceVersion,
            'event_type' => $eventType,
            'evidence_ref' => $evidenceRef,
            'payload' => $canonicalPayload,
        ];
        $encoded = json_encode($canonicalEnvelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $encoded);

        return DB::transaction(function () use (
            $tenantId, $sourceType, $sourceEventId, $sourceVersion, $eventType,
            $canonicalPayload, $digest, $evidenceRef
        ): array {
            $existing = FinanceInboxEvent::query()
                ->where('tenant_id', $tenantId)
                ->where('source_type', $sourceType)
                ->where('source_event_id', $sourceEventId)
                ->where('source_version', $sourceVersion)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (!hash_equals((string) $existing->payload_sha256, $digest)) {
                    throw new ConflictHttpException('The source event identity/version was replayed with a different payload.');
                }

                return ['event' => $existing, 'idempotent_replay' => true];
            }

            $event = FinanceInboxEvent::query()->create([
                'tenant_id' => $tenantId,
                'source_type' => $sourceType,
                'source_event_id' => $sourceEventId,
                'source_version' => $sourceVersion,
                'event_type' => $eventType,
                'payload' => $canonicalPayload,
                'payload_sha256' => $digest,
                'evidence_ref' => $evidenceRef,
                'status' => FinanceInboxEvent::QUARANTINED,
                'attempts' => 0,
                'last_error' => 'No authoritative source adapter is registered. Event retained for controlled resolution; no journal was created.',
                'received_at' => now(),
            ]);

            return ['event' => $event, 'idempotent_replay' => false];
        });
    }

    public function listForTenant(int $tenantId, ?string $status = null)
    {
        return FinanceInboxEvent::query()
            ->where('tenant_id', $tenantId)
            ->when($status !== null, static fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(50);
    }

    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? $this->canonicalize($item) : $item, $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
