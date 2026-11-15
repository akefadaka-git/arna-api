<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services\Receipts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Domains\Governance\Security\AgeReceiptCanonicalizer;
use RuntimeException;

/**
 * Writes one immutable, privacy-minimized receipt event in the caller's
 * lifecycle transaction. It never performs external network I/O.
 */
final class AgeReceiptOutbox
{
    public static function enqueue(int $tenantId, int $electionId, int $snapshotId, string $eventType, int $actorId): int
    {
        if (!DB::transactionLevel()) {
            throw new RuntimeException('AGE receipt outbox events must be inserted inside the lifecycle database transaction.');
        }
        if (!in_array($eventType, ['register.prepared', 'register.reconciled', 'register.approved_frozen'], true)) {
            throw new RuntimeException('Unsupported AGE receipt lifecycle event type.');
        }

        $snapshot = DB::table('governance_election_register_snapshots')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->where('id', $snapshotId)->lockForUpdate()->first();
        if ($snapshot === null || !is_string($snapshot->snapshot_uuid) || $snapshot->snapshot_uuid === '') {
            throw new RuntimeException('AGE receipt event requires a scoped snapshot UUID.');
        }

        $streamUuid = (string) $snapshot->snapshot_uuid;
        $previous = DB::table('age_receipt_outbox')
            ->where('stream_uuid', $streamUuid)->orderByDesc('sequence')->lockForUpdate()->first();
        if ($previous !== null && ($previous->delivery_state !== 'anchored'
            || !is_string($previous->receipt_sha256)
            || !preg_match('/^[a-f0-9]{64}$/D', $previous->receipt_sha256))) {
            throw new RuntimeException('The prior AGE lifecycle event must be independently anchored before the next transition can be recorded.');
        }
        $previousSequence = $previous === null ? 0 : (int) $previous->sequence;
        $previousReceiptHash = $previous === null ? null : (string) $previous->receipt_sha256;

        $stage = match ($eventType) {
            'register.prepared' => ['key_id' => $snapshot->prepared_attestation_key_id, 'digest' => $snapshot->prepared_attestation_sha256],
            'register.reconciled' => ['key_id' => $snapshot->reconciliation_attestation_key_id, 'digest' => $snapshot->reconciliation_attestation_sha256],
            'register.approved_frozen' => ['key_id' => $snapshot->approval_attestation_key_id, 'digest' => $snapshot->approval_attestation_sha256],
        };
        if (!is_string($stage['key_id']) || $stage['key_id'] === '' || !is_string($stage['digest']) || strlen($stage['digest']) !== 64) {
            throw new RuntimeException('AGE lifecycle receipt requires the corresponding application attestation.');
        }

        $eventUuid = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $occurredAt = Carbon::now();
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new RuntimeException('Application key is required to pseudonymize AGE receipt scope references.');
        }

        $payload = [
            'protocol_version' => '1.0',
            'schema_version' => '1.0.0',
            'event_id' => $eventUuid,
            'idempotency_key' => $idempotencyKey,
            'stream_id' => $streamUuid,
            'snapshot_id' => $streamUuid,
            'tenant_ref' => hash_hmac('sha256', 'tenant:' . $tenantId, $secret),
            'election_ref' => hash_hmac('sha256', 'election:' . $tenantId . ':' . $electionId, $secret),
            'event_type' => $eventType,
            'lifecycle_state' => (string) $snapshot->register_status,
            'occurred_at' => $occurredAt->toIso8601String(),
            'record_date' => substr((string) $snapshot->record_date, 0, 10),
            'voting_rule_version' => (string) $snapshot->rule_version,
            'source_evidence_sha256' => hash('sha256', (string) $snapshot->source_reference),
            'register_content_sha256' => (string) $snapshot->content_sha256,
            'voter_count' => (int) $snapshot->voter_count,
            'attestation' => [
                'stage' => $eventType,
                'key_id' => (string) $stage['key_id'],
                'sha256' => (string) $stage['digest'],
            ],
            'actor_ref' => hash_hmac('sha256', 'actor:' . $tenantId . ':' . $actorId, $secret),
        ];
        $canonical = AgeReceiptCanonicalizer::encode($payload);
        $eventHash = AgeReceiptCanonicalizer::eventHash($payload);

        return (int) DB::table('age_receipt_outbox')->insertGetId([
            'event_uuid' => $eventUuid,
            'stream_uuid' => $streamUuid,
            'snapshot_uuid' => $streamUuid,
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'snapshot_id' => $snapshotId,
            'sequence' => $previousSequence + 1,
            'event_type' => $eventType,
            'canonical_event' => $canonical,
            'event_sha256' => $eventHash,
            'idempotency_key' => $idempotencyKey,
            'expected_previous_sequence' => $previousSequence,
            'expected_previous_receipt_sha256' => $previousReceiptHash,
            'delivery_state' => 'pending',
            'attempt_count' => 0,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
